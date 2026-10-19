"""Comprueba que un fallo de auditoría no deje cambios de negocio sin historial."""
from __future__ import annotations

from contextlib import contextmanager
import unittest

import test_api


class AuditIntegrityTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        # Reutiliza el servidor y la base nomina_test_*; no ejecuta la otra suite.
        cls.addClassCleanup(test_api.ApiTests.doClassCleanups)
        test_api.ApiTests.setUpClass()
        cls.api = test_api.ApiTests()

    @contextmanager
    def audit_write_failure(self):
        before = self.api.audit()['total']
        self.api.fixture('fail-audit-writes')
        try:
            yield
        finally:
            self.api.fixture('restore-audit-writes')
        self.assertEqual(self.api.audit()['total'], before)

    def assert_request(self, route, method='GET', data=None, token=None, expected=200):
        status, result = self.api.request(route, method, data, token or self.api.token)
        self.assertEqual(status, expected, result)
        return result

    def test_failed_audit_preserves_password_and_sessions(self):
        user, credentials = self.api.new_user()
        login_data = {'email': user['email'], 'password': credentials['password']}
        session = self.assert_request('auth/login', 'POST', login_data)
        other = self.assert_request('auth/login', 'POST', login_data)
        token = session['token']
        before = self.assert_request('auth/sessions', token=token)

        with self.audit_write_failure():
            self.assert_request('auth/login', 'POST', login_data, expected=503)
            self.assertEqual(self.assert_request('auth/sessions', token=token), before)
            self.assert_request('auth/logout', 'POST', {}, token, expected=503)
            self.assert_request('auth/me', token=token)
            self.assert_request('auth/sessions/revoke-others', 'POST', {}, token, expected=503)
            self.assert_request('auth/me', token=other['token'])
            self.assert_request('auth/password', 'POST', {
                'current_password': credentials['password'],
                'new_password': 'Cambio2026!NoPersistir',
            }, token, expected=503)
            self.assertEqual(self.assert_request('auth/sessions', token=token), before)

        # La contraseña original sigue siendo válida tras el rollback.
        self.assert_request('auth/login', 'POST', login_data)
        self.assert_request('auth/login', 'POST', {
            'email': user['email'], 'password': 'Cambio2026!NoPersistir',
        }, expected=401)

    def test_failed_audit_rolls_back_record_creation_and_updates(self):
        employee = self.api.new_employee()
        attendance = self.api.new_attendance(employee['id'])
        incidence = self.api.new_incidence(employee['id'])
        attendance_route = f"attendance&employee_id={employee['id']}"
        incidence_route = f"incidences&employee_id={employee['id']}"
        attendance_before = self.assert_request(attendance_route)
        incidences_before = self.assert_request(incidence_route)

        with self.audit_write_failure():
            self.assert_request('attendance', 'POST', {
                'employee_id': employee['id'], 'work_date': '2026-07-02', 'status': 'present',
            }, expected=503)
            self.assert_request(f"attendance/{attendance['id']}", 'PATCH', {
                'status': 'absent',
            }, expected=503)
            self.assert_request('incidences', 'POST', {
                'employee_id': employee['id'], 'occurrence_date': '2026-07-02',
                'type': 'deduction', 'concept': 'No persistir', 'amount': '100.00',
            }, expected=503)
            self.assert_request(f"incidences/{incidence['id']}", 'PATCH', {
                'amount': '999.00',
            }, expected=503)

        self.assertEqual(self.assert_request(attendance_route), attendance_before)
        self.assertEqual(self.assert_request(incidence_route), incidences_before)

    def test_failed_audit_rolls_back_payroll_creation_payment_and_cancellation(self):
        employee = self.api.new_employee()
        self.api.new_attendance(employee['id'])
        incidence = self.api.new_incidence(employee['id'])
        payload = {
            'period_start': '2026-07-01', 'period_end': '2026-07-15',
            'use_records': True, 'items': [{'employee_id': employee['id']}],
        }
        payrolls_before = self.assert_request('payrolls')
        with self.audit_write_failure():
            self.assert_request('payrolls', 'POST', payload, expected=503)
        self.assertEqual(self.assert_request('payrolls'), payrolls_before)

        # Volver a crear verifica también que no quedaron incidencias aplicadas
        # ni un periodo cerrado por la nómina cuya transacción se revirtió.
        created = self.assert_request('payrolls', 'POST', payload, expected=201)
        payroll_id = created['payroll']['id']
        self.assertEqual(created['incidence_ids'], [incidence['id']])
        before = self.assert_request(f'payrolls/{payroll_id}')
        with self.audit_write_failure():
            self.assert_request(f'payrolls/{payroll_id}/pay', 'POST', {}, expected=503)
            self.assertEqual(self.assert_request(f'payrolls/{payroll_id}'), before)
            self.assert_request(f'payrolls/{payroll_id}/cancel', 'POST', {
                'reason': 'Cancelación que debe revertirse',
            }, expected=503)
            self.assertEqual(self.assert_request(f'payrolls/{payroll_id}'), before)

        cancelled = self.assert_request(f'payrolls/{payroll_id}/cancel', 'POST', {
            'reason': 'Cancelación registrada',
        })
        self.assertEqual(cancelled['payroll']['status'], 'cancelled')
        total = self.api.audit(action='payroll.cancelled')['total']
        self.assert_request(f'payrolls/{payroll_id}/cancel', 'POST', {
            'reason': 'Reintento de cancelación',
        })
        self.assertEqual(self.api.audit(action='payroll.cancelled')['total'], total)


if __name__ == '__main__':
    unittest.main(verbosity=2)
