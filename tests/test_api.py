"""Pruebas HTTP reales con una base temporal: no cambian la nómina del usuario."""
from __future__ import annotations

import hashlib
import csv
import io
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import time
import unittest
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen
from urllib.parse import urlencode
import uuid

ROOT = Path(__file__).resolve().parents[1]
PHP = shutil.which('php') or r'C:\xampp\php\php.exe'


class ApiTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if not (ROOT / 'config.php').is_file():
            raise RuntimeError('Ejecuta primero php scripts/install.php --demo para crear config.php.')
        cls.env = {**os.environ, 'NOMINA_DB_NAME': 'nomina_test_' + uuid.uuid4().hex[:12]}
        cls.server = None
        cls.log = None
        cls.addClassCleanup(cls.cleanup)
        installed = subprocess.run([PHP, 'scripts/install.php', '--demo'], cwd=ROOT, env=cls.env, capture_output=True, text=True, encoding='utf-8')
        if installed.returncode:
            raise RuntimeError(installed.stderr)
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        (ROOT / 'storage').mkdir(exist_ok=True)
        cls.log = (ROOT / 'storage' / 'test-server.log').open('w', encoding='utf-8')
        cls.server = subprocess.Popen([PHP, '-S', f'127.0.0.1:{port}', '-t', 'public'], cwd=ROOT, env=cls.env, stdout=cls.log, stderr=cls.log)
        cls.url = f'http://127.0.0.1:{port}/api/index.php?route='
        for _ in range(60):
            try:
                status, body = cls.request('health')
                if status == 200:
                    break
            except (URLError, ConnectionError):
                pass
            time.sleep(.1)
        else:
            raise RuntimeError('La API de pruebas no inició. Revisa storage/test-server.log.')
        code, result = cls.request('auth/login', 'POST', {'email': 'admin@nomina.local', 'password': 'Nomina2026!'})
        if code != 200:
            raise RuntimeError(f'No se pudo autenticar el usuario demo: {result}')
        cls.token = result['token']
        cls.fixture('viewer')

    @classmethod
    def cleanup(cls):
        if cls.server:
            cls.server.terminate()
            try:
                cls.server.wait(timeout=5)
            except subprocess.TimeoutExpired:
                cls.server.kill()
                cls.server.wait(timeout=5)
        if cls.log:
            cls.log.close()
        cleanup = subprocess.run([PHP, 'tests/database_fixture.php', 'cleanup'], cwd=ROOT, env=cls.env, capture_output=True, text=True, encoding='utf-8')
        if cleanup.returncode:
            print('No se eliminó la base temporal:', cls.env['NOMINA_DB_NAME'], cleanup.stderr)

    @classmethod
    def fixture(cls, action, text=''):
        result = subprocess.run([PHP, 'tests/database_fixture.php', action], input=text, cwd=ROOT, env=cls.env, capture_output=True, text=True, encoding='utf-8')
        if result.returncode:
            raise RuntimeError(result.stderr)
        return json.loads(result.stdout) if result.stdout else None

    @classmethod
    def request(cls, route, method='GET', data=None, token=None, raw=None):
        headers = {'Accept': 'application/json'}
        if token:
            headers['Authorization'] = 'Bearer ' + token
        if raw is not None or data is not None:
            headers['Content-Type'] = 'application/json'
        content = raw if raw is not None else (json.dumps(data).encode() if data is not None else None)
        request = Request(cls.url + route, data=content, headers=headers, method=method)
        try:
            response = urlopen(request, timeout=8)
        except HTTPError as error:
            response = error
        with response:
            return response.status, json.loads(response.read().decode('utf-8'))

    def new_employee(self, **changes):
        code = 'T-' + uuid.uuid4().hex[:10]
        payload = {'employee_code': code, 'full_name': 'Persona de pruebas', 'email': code + '@example.test', 'department': 'Pruebas', 'position': 'Analista', 'monthly_salary': '18000.00', 'hire_date': '2024-01-01', **changes}
        status, body = self.request('employees', 'POST', payload, self.token)
        self.assertEqual(status, 201, body)
        return body['employee']

    def payroll_data(self, employee_id, **changes):
        return {'period_start': '2026-08-01', 'period_end': '2026-08-15', 'items': [{'employee_id': employee_id, 'days_worked': 15, 'bonus': '500.00', 'deduction': '250.00'}], **changes}

    def new_attendance(self, employee_id, **changes):
        payload = {'employee_id': employee_id, 'work_date': '2026-07-01', 'status': 'present', **changes}
        status, result = self.request('attendance', 'POST', payload, self.token)
        self.assertEqual(status, 201, result)
        return result['attendance']

    def new_incidence(self, employee_id, **changes):
        payload = {'employee_id': employee_id, 'occurrence_date': '2026-07-01', 'type': 'bonus', 'concept': 'Bono de prueba', 'amount': '500.00', **changes}
        status, result = self.request('incidences', 'POST', payload, self.token)
        self.assertEqual(status, 201, result)
        return result['incidence']

    def new_user(self, **changes):
        payload = {'name': 'Usuario de pruebas', 'email': uuid.uuid4().hex + '@example.test', 'password': 'Usuario2026!Pruebas', 'role': 'viewer', 'status': 'active', **changes}
        code, result = self.request('users', 'POST', payload, self.token)
        self.assertEqual(code, 201, result)
        return result['user'], payload

    def audit(self, **filters):
        code, result = self.request('audit&' + urlencode(filters), token=self.token)
        self.assertEqual(code, 200, result)
        return result

    def export_csv(self, route):
        request = Request(self.url + route, headers={'Authorization': 'Bearer ' + self.token})
        with urlopen(request, timeout=8) as response:
            self.assertEqual(response.status, 200)
            self.assertIn('text/csv', response.headers['Content-Type'])
            self.assertIn('attachment', response.headers['Content-Disposition'])
            return list(csv.reader(io.StringIO(response.read().decode('utf-8-sig'))))

    def test_health(self):
        code, body = self.request('health')
        self.assertEqual(code, 200)
        self.assertEqual(body['database'], 'connected')

    def test_requires_authentication(self):
        for route in ['auth/me', 'employees', 'dashboard', 'payrolls', 'attendance', 'incidences', 'payrolls/preview', 'payments', 'reports/dashboard', 'reports/employees', 'reports/payrolls/1', 'users', 'audit']:
            with self.subTest(route=route):
                code, body = self.request(route)
                self.assertEqual(code, 401, body)
                self.assertFalse(body['authenticated'])

    def test_valid_login_and_profile(self):
        code, body = self.request('auth/me', token=self.token)
        self.assertEqual(code, 200)
        self.assertTrue(body['authenticated'])
        self.assertEqual(body['user']['email'], 'admin@nomina.local')
        self.assertNotIn('password_hash', body['user'])

    def test_wrong_password(self):
        code, body = self.request('auth/login', 'POST', {'email': 'admin@nomina.local', 'password': 'incorrecta'})
        self.assertEqual(code, 401)
        self.assertFalse(body['authenticated'])
        self.assertNotIn('token', body)

    def test_login_rejects_invalid_and_unicode_email_aliases(self):
        # La colación SQL puede equiparar estos acentos con el correo real;
        # no deben abrir sesiones ni crear contadores alternos para esa cuenta.
        for email in ['ádmin@nomina.local', 'a\u0301dmin@nomina.local', 'admin@nomína.local', '', 'sin-arroba', 'admin@@nomina.local', 'admin\x00@nomina.local']:
            with self.subTest(email=email):
                code, body = self.request('auth/login', 'POST', {'email': email, 'password': 'Nomina2026!'})
                self.assertEqual(code, 422, body)
                self.assertFalse(body['authenticated'])
                self.assertNotIn('token', body)

    def test_login_normalizes_email_case_and_outer_spaces(self):
        code, session = self.request('auth/login', 'POST', {'email': '  ADMIN@NOMINA.LOCAL  ', 'password': 'Nomina2026!'})
        self.assertEqual(code, 200, session)
        self.assertTrue(session['authenticated'])
        self.assertEqual(session['user']['email'], 'admin@nomina.local')
        self.assertEqual(self.request('auth/me', token=session['token'])[0], 200)
        self.assertEqual(self.request('auth/logout', 'POST', {}, session['token'])[0], 200)
        self.assertEqual(self.request('auth/me', token=session['token'])[0], 401)

    def test_password_input_boundaries(self):
        for password in ('a' * 73, 'á' * 37, 'Nomina2026!\x00sufijo'):
            with self.subTest(password_bytes=len(password.encode('utf-8'))):
                code, body = self.request('auth/login', 'POST', {'email': 'admin@nomina.local', 'password': password})
                self.assertEqual(code, 422, body)
                self.assertFalse(body['authenticated'])
                self.assertNotIn('token', body)

    def test_sql_injection_is_rejected(self):
        code, body = self.request('auth/login', 'POST', {'email': "' OR 1=1 --", 'password': 'irrelevante'})
        self.assertEqual(code, 422, body)
        self.assertNotIn('token', body)

    def test_malformed_json(self):
        code, body = self.request('employees', 'POST', token=self.token, raw=b'{broken')
        self.assertEqual(code, 400, body)

    def test_fake_bearer_token(self):
        code, body = self.request('auth/me', token='f' * 64)
        self.assertEqual(code, 401)
        self.assertFalse(body['authenticated'])

    def test_expired_token(self):
        _, session = self.request('auth/login', 'POST', {'email': 'admin@nomina.local', 'password': 'Nomina2026!'})
        self.fixture('expire', hashlib.sha256(session['token'].encode()).hexdigest())
        code, body = self.request('auth/me', token=session['token'])
        self.assertEqual(code, 401, body)

    def test_logout_revokes_token(self):
        _, session = self.request('auth/login', 'POST', {'email': 'admin@nomina.local', 'password': 'Nomina2026!'})
        code, result = self.request('auth/logout', 'POST', {}, session['token'])
        self.assertEqual(code, 200, result)
        self.assertFalse(result['authenticated'])
        code, result = self.request('auth/me', token=session['token'])
        self.assertEqual(code, 401, result)

    def test_hashes_at_rest(self):
        stored = self.fixture('stored-credentials')
        self.assertTrue(stored['password_hashed'])
        self.assertNotIn(self.token, stored['token_hashes'])
        self.assertIn(hashlib.sha256(self.token.encode()).hexdigest(), stored['token_hashes'])

    def test_account_session_revocation_and_password_change(self):
        self.fixture('account')
        credentials = {'email': 'account@example.test', 'password': 'Account2026!Original'}
        code, current = self.request('auth/login', 'POST', credentials)
        self.assertEqual(code, 200, current)
        code, other = self.request('auth/login', 'POST', credentials)
        self.assertEqual(code, 200, other)
        code, sessions = self.request('auth/sessions', token=current['token'])
        self.assertEqual(code, 200, sessions)
        self.assertEqual(len(sessions['sessions']), 2)
        self.assertEqual(sum(row['current'] for row in sessions['sessions']), 1)
        code, result = self.request('auth/sessions/revoke-others', 'POST', {}, current['token'])
        self.assertEqual(code, 200, result)
        self.assertEqual(result['revoked_count'], 1)
        self.assertEqual(self.request('auth/me', token=other['token'])[0], 401)
        self.assertEqual(self.request('auth/me', token=current['token'])[0], 200)
        code, result = self.request('auth/password', 'POST', {'current_password': credentials['password'], 'new_password': 'corta'}, current['token'])
        self.assertEqual(code, 422, result)
        code, result = self.request('auth/change-password', 'POST', {'current_password': credentials['password'], 'new_password': 'Account2026!Updated'}, current['token'])
        self.assertEqual(code, 200, result)
        self.assertFalse(result['authenticated'])
        self.assertEqual(self.request('auth/me', token=current['token'])[0], 401)
        self.assertEqual(self.request('auth/login', 'POST', credentials)[0], 401)
        code, result = self.request('auth/login', 'POST', {**credentials, 'password': 'Account2026!Updated'})
        self.assertEqual(code, 200, result)
        actions = [row['action'] for row in self.audit(actor_id=current['user']['id'], per_page=100)['items']]
        self.assertEqual(actions.count('auth.password_changed'), 1)
        self.assertEqual(actions.count('auth.sessions_revoked'), 1)
        self.assertEqual(actions.count('auth.login'), 3)

    def test_employee_update_and_duplicate(self):
        employee = self.new_employee()
        for name in ['Nombre\x00', '\x00Nombre']:
            code, result = self.request('employees/' + str(employee['id']), 'PATCH', {'full_name': name}, self.token)
            self.assertEqual(code, 422, result)
        code, result = self.request('employees/' + str(employee['id']), 'PATCH', {'full_name': 'Nombre actualizado', 'status': 'inactive'}, self.token)
        self.assertEqual(code, 200, result)
        self.assertEqual(result['employee']['status'], 'inactive')
        self.assertEqual(result['employee']['full_name'], 'Nombre actualizado')
        payload = {k: employee[k] for k in ['employee_code', 'full_name', 'email', 'department', 'position', 'monthly_salary', 'hire_date']}
        code, result = self.request('employees', 'POST', payload, self.token)
        self.assertEqual(code, 409, result)

    def test_payroll_calculation_snapshot_and_payment(self):
        employee = self.new_employee()
        code, result = self.request('payrolls', 'POST', self.payroll_data(employee['id']), self.token)
        self.assertEqual(code, 201, result)
        payroll_id = result['payroll']['id']
        self.assertEqual(float(result['payroll']['total_net']), 9250)
        self.request('employees/' + str(employee['id']), 'PATCH', {'monthly_salary': '30000.00', 'full_name': 'Nuevo nombre'}, self.token)
        code, snapshot = self.request('payrolls/' + str(payroll_id), token=self.token)
        self.assertEqual(code, 200, snapshot)
        self.assertEqual(float(snapshot['items'][0]['monthly_salary']), 18000)
        self.assertEqual(snapshot['items'][0]['full_name'], employee['full_name'])
        code, paid = self.request(f'payrolls/{payroll_id}/pay', 'POST', {}, self.token)
        self.assertEqual(code, 200, paid)
        self.assertEqual(paid['payroll']['status'], 'paid')
        code, repeated = self.request(f'payrolls/{payroll_id}/pay', 'POST', {}, self.token)
        self.assertEqual(code, 200, repeated)
        self.assertEqual(repeated['payroll']['paid_at'], paid['payroll']['paid_at'])
        code, payments = self.request('payments', token=self.token)
        self.assertEqual(code, 200, payments)
        payment = next(row for row in payments['payments'] if row['id'] == payroll_id)
        self.assertEqual(float(payment['total_net']), 9250)
        self.assertEqual(payment['paid_at'], paid['payroll']['paid_at'])

    def test_payroll_overlap_rejected(self):
        employee = self.new_employee()
        payload = self.payroll_data(employee['id'])
        code, result = self.request('payrolls', 'POST', payload, self.token)
        self.assertEqual(code, 201, result)
        code, result = self.request('payrolls', 'POST', payload, self.token)
        self.assertEqual(code, 409, result)

    def test_payroll_cancellation_requires_admin_and_valid_reason(self):
        employee = self.new_employee()
        code, created = self.request('payrolls', 'POST', self.payroll_data(employee['id']), self.token)
        self.assertEqual(code, 201, created)
        route = f"payrolls/{created['payroll']['id']}/cancel"
        reason = {'reason': 'Corrección de los registros del periodo'}
        code, result = self.request(route, 'POST', reason)
        self.assertEqual(code, 401, result)
        code, viewer = self.request('auth/login', 'POST', {'email': 'viewer@example.test', 'password': 'Viewer2026!'})
        self.assertEqual(code, 200, viewer)
        code, result = self.request(route, 'POST', reason, viewer['token'])
        self.assertEqual(code, 403, result)
        self.assertEqual(self.request('auth/logout', 'POST', {}, viewer['token'])[0], 200)
        for data in [{}, {'reason': ''}, {'reason': 'ab'}, {'reason': '  ab  '}, {'reason': 'x' * 501}, {'reason': 'á' * 501}, {'reason': '\x00Motivo'}, {'reason': 'Motivo\n'}, {'reason': 123}, {**reason, 'unexpected': True}]:
            with self.subTest(data=data):
                code, result = self.request(route, 'POST', data, self.token)
                self.assertEqual(code, 422, result)
        self.assertEqual(self.request(route, token=self.token)[0], 405)
        code, result = self.request('payrolls/99999999/cancel', 'POST', reason, self.token)
        self.assertEqual(code, 404, result)
        code, unchanged = self.request(f"payrolls/{created['payroll']['id']}", token=self.token)
        self.assertEqual(code, 200, unchanged)
        self.assertEqual(unchanged['payroll']['status'], 'draft')
        for field in ['cancelled_at', 'cancelled_by', 'cancellation_reason']:
            self.assertIsNone(unchanged['payroll'][field])

    def test_payroll_cancellation_preserves_snapshot_and_original_reason(self):
        employee = self.new_employee()
        code, created = self.request('payrolls', 'POST', self.payroll_data(employee['id']), self.token)
        self.assertEqual(code, 201, created)
        payroll_id = created['payroll']['id']
        reason = 'Corrección de días y bonos'
        code, cancelled = self.request(f'payrolls/{payroll_id}/cancel', 'POST', {'reason': '  ' + reason + '  '}, self.token)
        self.assertEqual(code, 200, cancelled)
        self.assertEqual(cancelled['payroll']['status'], 'cancelled')
        self.assertTrue(cancelled['payroll']['cancelled_at'])
        self.assertEqual(cancelled['payroll']['cancelled_by'], created['payroll']['created_by'])
        self.assertEqual(cancelled['payroll']['cancellation_reason'], reason)
        self.assertIsNone(cancelled['payroll']['paid_at'])
        self.assertEqual(cancelled['items'], created['items'])
        self.assertEqual(cancelled['incidence_ids'], created['incidence_ids'])
        code, repeated = self.request(f'payrolls/{payroll_id}/cancel', 'POST', {'reason': 'Otro motivo posterior'}, self.token)
        self.assertEqual(code, 200, repeated)
        self.assertEqual(repeated, cancelled)
        code, result = self.request(f'payrolls/{payroll_id}/pay', 'POST', {}, self.token)
        self.assertEqual(code, 409, result)
        self.assertEqual(self.request(f'payrolls/{payroll_id}', token=self.token)[1], cancelled)
        rows = self.export_csv(f'reports/payrolls/{payroll_id}')
        row = dict(zip(rows[0], rows[1]))
        self.assertEqual(row['Estado'], 'Cancelada')
        self.assertEqual(row['Motivo de cancelación'], reason)
        self.assertEqual(row['Fecha de cancelación (UTC)'], cancelled['payroll']['cancelled_at'])
        self.assertEqual(self.export_csv(f'payrolls/{payroll_id}/export'), rows)

    def test_paid_payroll_cannot_be_cancelled(self):
        employee = self.new_employee()
        code, created = self.request('payrolls', 'POST', self.payroll_data(employee['id']), self.token)
        self.assertEqual(code, 201, created)
        payroll_id = created['payroll']['id']
        code, paid = self.request(f'payrolls/{payroll_id}/pay', 'POST', {}, self.token)
        self.assertEqual(code, 200, paid)
        code, result = self.request(f'payrolls/{payroll_id}/cancel', 'POST', {'reason': 'Intento posterior al pago'}, self.token)
        self.assertEqual(code, 409, result)
        self.assertEqual(self.request(f'payrolls/{payroll_id}', token=self.token)[1], paid)

    def test_cancelled_payroll_is_excluded_from_pending_and_payment_totals(self):
        active_employee = self.new_employee()
        cancelled_employee = self.new_employee(monthly_salary='24000.00')
        code, active = self.request('payrolls', 'POST', self.payroll_data(active_employee['id']), self.token)
        self.assertEqual(code, 201, active)
        code, created = self.request('payrolls', 'POST', self.payroll_data(cancelled_employee['id']), self.token)
        self.assertEqual(code, 201, created)
        payroll_id = created['payroll']['id']
        code, before = self.request('reports/dashboard', token=self.token)
        self.assertEqual(code, 200, before)
        self.assertEqual(before['stats']['last_run_total'], created['payroll']['total_net'])
        code, result = self.request(f'payrolls/{payroll_id}/cancel', 'POST', {'reason': 'Revisión pendiente del periodo'}, self.token)
        self.assertEqual(code, 200, result)
        code, after = self.request('reports/dashboard', token=self.token)
        self.assertEqual(code, 200, after)
        self.assertEqual(after['stats']['last_run_total'], active['payroll']['total_net'])
        self.assertEqual(after['stats']['pending_runs'], before['stats']['pending_runs'] - 1)
        self.assertEqual(after['stats']['total_runs'], before['stats']['total_runs'])
        self.assertEqual(after['stats']['paid_total'], before['stats']['paid_total'])
        self.assertEqual(after['monthly_payments'], before['monthly_payments'])
        code, payments = self.request('payments', token=self.token)
        self.assertEqual(code, 200, payments)
        self.assertNotIn(payroll_id, [row['id'] for row in payments['payments']])

    def test_cancelled_payroll_reopens_records_without_losing_historical_links(self):
        employee = self.new_employee()
        attendance = self.new_attendance(employee['id'])
        bonus = self.new_incidence(employee['id'])
        payload = {'period_start': '2026-07-01', 'period_end': '2026-07-15', 'use_records': True, 'items': [{'employee_id': employee['id']}]}
        preview_route = f"payrolls/preview&employee_id={employee['id']}&period_start=2026-07-01&period_end=2026-07-15"
        code, original = self.request('payrolls', 'POST', payload, self.token)
        self.assertEqual(code, 201, original)
        original_id = original['payroll']['id']
        self.assertEqual(original['payroll']['total_net'], 1100)
        self.assertEqual(original['incidence_ids'], [bonus['id']])
        self.assertEqual(self.request(f"attendance/{attendance['id']}", 'PATCH', {'status': 'absent'}, self.token)[0], 409)
        self.assertEqual(self.request(f"incidences/{bonus['id']}", 'PATCH', {'amount': '750.00'}, self.token)[0], 409)
        code, cancelled = self.request(f'payrolls/{original_id}/cancel', 'POST', {'reason': 'Corregir asistencia y conceptos'}, self.token)
        self.assertEqual(code, 200, cancelled)
        self.assertEqual(cancelled['items'], original['items'])
        self.assertEqual(cancelled['incidence_ids'], original['incidence_ids'])
        code, result = self.request(f"attendance/{attendance['id']}", 'PATCH', {'status': 'absent'}, self.token)
        self.assertEqual(code, 200, result)
        code, result = self.request(f"incidences/{bonus['id']}", 'PATCH', {'amount': '750.00'}, self.token)
        self.assertEqual(code, 200, result)
        self.new_attendance(employee['id'], work_date='2026-07-02', status='paid_leave')
        deduction = self.new_incidence(employee['id'], type='deduction', amount='50.00')
        code, preview = self.request(preview_route, token=self.token)
        self.assertEqual(code, 200, preview)
        self.assertTrue(preview['can_create'], preview)
        self.assertEqual(preview['totals'], {'gross': 1350, 'deductions': 50, 'net': 1300})
        code, replacement = self.request('payrolls', 'POST', payload, self.token)
        self.assertEqual(code, 201, replacement)
        replacement_id = replacement['payroll']['id']
        self.assertNotEqual(replacement_id, original_id)
        self.assertEqual(replacement['payroll']['total_net'], 1300)
        self.assertEqual(set(replacement['incidence_ids']), {bonus['id'], deduction['id']})
        self.assertEqual(self.request(f'payrolls/{original_id}', token=self.token)[1], cancelled)
        # La nómina nueva vuelve a cerrar el periodo y consume la incidencia.
        self.assertEqual(self.request(f"incidences/{bonus['id']}", 'PATCH', {'amount': '800.00'}, self.token)[0], 409)
        self.assertFalse(self.request(preview_route, token=self.token)[1]['can_create'])
        code, result = self.request(f'payrolls/{replacement_id}/cancel', 'POST', {'reason': 'Segunda revisión de conceptos'}, self.token)
        self.assertEqual(code, 200, result)
        code, preview = self.request(preview_route, token=self.token)
        self.assertEqual(code, 200, preview)
        self.assertTrue(preview['can_create'], preview)
        # Dos vínculos históricos cancelados no deben duplicar el mismo bono.
        self.assertEqual(preview['totals'], {'gross': 1350, 'deductions': 50, 'net': 1300})
        self.assertEqual(set(preview['items'][0]['incidence_ids']), {bonus['id'], deduction['id']})
        self.assertEqual(self.request(f'payrolls/{original_id}', token=self.token)[1], cancelled)

    def test_invalid_payroll_is_atomic(self):
        employee = self.new_employee()
        payload = self.payroll_data(employee['id'])
        payload['items'].append({'employee_id': 99999999, 'days_worked': 15, 'bonus': 0, 'deduction': 0})
        _, before = self.request('payrolls', token=self.token)
        code, result = self.request('payrolls', 'POST', payload, self.token)
        self.assertIn(code, (404, 422), result)
        _, after = self.request('payrolls', token=self.token)
        self.assertEqual(len(before['payrolls']), len(after['payrolls']))

    def test_negative_or_excessive_deductions_rejected(self):
        employee = self.new_employee()
        for deduction in ['-1.00', '1000000.00']:
            payload = self.payroll_data(employee['id'])
            payload['items'][0]['deduction'] = deduction
            code, result = self.request('payrolls', 'POST', payload, self.token)
            self.assertEqual(code, 422, result)

    def test_inactive_employee_rejected(self):
        employee = self.new_employee(status='inactive')
        code, result = self.request('payrolls', 'POST', self.payroll_data(employee['id']), self.token)
        self.assertEqual(code, 422, result)

    def test_viewer_cannot_write(self):
        code, session = self.request('auth/login', 'POST', {'email': 'viewer@example.test', 'password': 'Viewer2026!'})
        self.assertEqual(code, 200, session)
        code, result = self.request('employees', token=session['token'])
        self.assertEqual(code, 200, result)
        code, result = self.request('employees', 'POST', {}, session['token'])
        self.assertEqual(code, 403, result)
        for route, method in [('attendance', 'POST'), ('attendance/1', 'PATCH'), ('incidences', 'POST'), ('incidences/1', 'PATCH'), ('payrolls', 'POST'), ('payrolls/1/pay', 'POST')]:
            with self.subTest(route=route):
                code, result = self.request(route, method, {}, session['token'])
                self.assertEqual(code, 403, result)
        for route in ['attendance', 'incidences', 'payments', 'reports/dashboard']:
            code, result = self.request(route, token=session['token'])
            self.assertEqual(code, 200, result)

    def test_attendance_create_update_filter_and_duplicate(self):
        employee = self.new_employee()
        entry = self.new_attendance(employee['id'])
        code, result = self.request(f"attendance/{entry['id']}", 'PATCH', {'status': 'paid_leave', 'notes': 'Permiso pagado'}, self.token)
        self.assertEqual(code, 200, result)
        self.assertEqual(result['attendance']['status'], 'paid_leave')
        self.assertEqual(result['attendance']['notes'], 'Permiso pagado')
        self.new_attendance(employee['id'], work_date='2026-07-02', status='absent')
        route = f"attendance&employee_id={employee['id']}&period_start=2026-07-01&period_end=2026-07-01"
        code, result = self.request(route, token=self.token)
        self.assertEqual(code, 200, result)
        self.assertEqual([row['id'] for row in result['attendance']], [entry['id']])
        code, result = self.request('attendance', 'POST', {'employee_id': employee['id'], 'work_date': '2026-07-01', 'status': 'present'}, self.token)
        self.assertEqual(code, 409, result)

    def test_attendance_validation(self):
        employee = self.new_employee(hire_date='2026-07-01')
        payload = {'employee_id': employee['id'], 'work_date': '2026-07-01', 'status': 'present'}
        for changes in [{'status': 'unknown'}, {'work_date': '2026-02-30'}, {'work_date': '2026-06-30'}, {'notes': 'x' * 256}, {'notes': '\x00'}, {'unexpected': True}]:
            with self.subTest(changes=changes):
                code, result = self.request('attendance', 'POST', {**payload, **changes}, self.token)
                self.assertEqual(code, 422, result)
        code, result = self.request('attendance', 'POST', {**payload, 'employee_id': 99999999}, self.token)
        self.assertEqual(code, 404, result)
        inactive = self.new_employee(status='inactive')
        code, result = self.request('attendance', 'POST', {**payload, 'employee_id': inactive['id']}, self.token)
        self.assertEqual(code, 422, result)

    def test_incidence_create_update_and_filter(self):
        employee = self.new_employee()
        entry = self.new_incidence(employee['id'])
        code, result = self.request(f"incidences/{entry['id']}", 'PATCH', {'type': 'deduction', 'amount': '123.45', 'concept': 'Ajuste acordado'}, self.token)
        self.assertEqual(code, 200, result)
        self.assertEqual(result['incidence']['type'], 'deduction')
        self.assertEqual(float(result['incidence']['amount']), 123.45)
        self.new_incidence(employee['id'], occurrence_date='2026-07-02')
        route = f"incidences&employee_id={employee['id']}&period_start=2026-07-01&period_end=2026-07-01"
        code, result = self.request(route, token=self.token)
        self.assertEqual(code, 200, result)
        self.assertEqual([row['id'] for row in result['incidences']], [entry['id']])

    def test_incidence_validation(self):
        employee = self.new_employee(hire_date='2026-07-01')
        payload = {'employee_id': employee['id'], 'occurrence_date': '2026-07-01', 'type': 'bonus', 'concept': 'Bono', 'amount': '1.00'}
        changes_list = [{'type': 'unknown'}, {'occurrence_date': '2026-02-30'}, {'occurrence_date': '2026-06-30'}, {'concept': ''}, {'concept': 'Bono\x00'}, {'concept': '\x00Bono'}, {'amount': '0'}, {'amount': '-1.00'}, {'amount': '1.001'}, {'amount': '10000000.01'}, {'unexpected': True}]
        for changes in changes_list:
            with self.subTest(changes=changes):
                code, result = self.request('incidences', 'POST', {**payload, **changes}, self.token)
                self.assertEqual(code, 422, result)
        code, result = self.request('incidences', 'POST', {**payload, 'employee_id': 99999999}, self.token)
        self.assertEqual(code, 404, result)
        inactive = self.new_employee(status='inactive')
        code, result = self.request('incidences', 'POST', {**payload, 'employee_id': inactive['id']}, self.token)
        self.assertEqual(code, 422, result)

    def test_record_employee_cannot_be_reassigned(self):
        employee, other = self.new_employee(), self.new_employee()
        attendance = self.new_attendance(employee['id'])
        incidence = self.new_incidence(employee['id'])
        for route in [f"attendance/{attendance['id']}", f"incidences/{incidence['id']}"]:
            code, result = self.request(route, 'PATCH', {'employee_id': other['id']}, self.token)
            self.assertEqual(code, 409, result)

    def test_payroll_from_records_preview_totals_links_and_period_lock(self):
        employee = self.new_employee()
        attendance = self.new_attendance(employee['id'])
        self.new_attendance(employee['id'], work_date='2026-07-02', status='paid_leave')
        self.new_attendance(employee['id'], work_date='2026-07-03', status='absent')
        bonus = self.new_incidence(employee['id'])
        deduction = self.new_incidence(employee['id'], type='deduction', amount='250.00', concept='Deducción de prueba')
        self.new_incidence(employee['id'], occurrence_date='2026-07-20', amount='999.00')
        route = f"payrolls/preview&employee_id={employee['id']}&period_start=2026-07-01&period_end=2026-07-15"
        code, preview = self.request(route, token=self.token)
        self.assertEqual(code, 200, preview)
        row = next(row for row in preview['items'] if row['employee_id'] == employee['id'])
        self.assertTrue(row['can_create'], row)
        self.assertEqual(row['days_worked'], 2)
        self.assertEqual(row['attendance_count'], 3)
        self.assertEqual(row['absent_days'], 1)
        self.assertEqual(float(row['gross_pay']), 1700)
        self.assertEqual(float(row['net_pay']), 1450)
        self.assertEqual(set(row['incidence_ids']), {bonus['id'], deduction['id']})
        self.assertTrue(preview['can_create'])
        self.assertEqual(preview['totals'], {'gross': 1700, 'deductions': 250, 'net': 1450})
        # Los importes del navegador no pueden alterar el cálculo desde registros.
        payload = {'period_start': '2026-07-01', 'period_end': '2026-07-15', 'use_records': True, 'items': [{'employee_id': employee['id'], 'days_worked': 30, 'bonus': '999999.00', 'deduction': 0}]}
        code, result = self.request('payrolls', 'POST', payload, self.token)
        self.assertEqual(code, 201, result)
        self.assertEqual(float(result['payroll']['total_gross']), 1700)
        self.assertEqual(float(result['payroll']['total_deductions']), 250)
        self.assertEqual(float(result['payroll']['total_net']), 1450)
        self.assertEqual(result['items'][0]['days_worked'], 2)
        self.assertEqual(set(result['incidence_ids']), {bonus['id'], deduction['id']})
        payroll_id = result['payroll']['id']
        code, stored = self.request(f'payrolls/{payroll_id}', token=self.token)
        self.assertEqual(code, 200, stored)
        self.assertEqual(stored['incidence_ids'], result['incidence_ids'])
        for path, method, data in [
            (f"attendance/{attendance['id']}", 'PATCH', {'status': 'absent'}),
            (f"attendance/{attendance['id']}", 'PATCH', {'work_date': '2026-07-22'}),
            ('attendance', 'POST', {'employee_id': employee['id'], 'work_date': '2026-07-05', 'status': 'present'}),
            (f"incidences/{bonus['id']}", 'PATCH', {'amount': '1.00'}),
            ('incidences', 'POST', {'employee_id': employee['id'], 'occurrence_date': '2026-07-04', 'type': 'bonus', 'concept': 'Tardío', 'amount': '1.00'}),
        ]:
            with self.subTest(path=path, data=data):
                code, result = self.request(path, method, data, self.token)
                self.assertEqual(code, 409, result)
        code, preview = self.request(route, token=self.token)
        self.assertEqual(code, 200, preview)
        row = next(row for row in preview['items'] if row['employee_id'] == employee['id'])
        self.assertFalse(row['can_create'])
        self.assertTrue(row['errors'])

    def test_records_excess_deduction_rejected_without_consuming_incidence(self):
        employee = self.new_employee()
        incidence = self.new_incidence(employee['id'], type='deduction', amount='1.00')
        payload = {'period_start': '2026-07-01', 'period_end': '2026-07-15', 'use_records': True, 'items': [{'employee_id': employee['id']}]}
        _, before = self.request('payrolls', token=self.token)
        code, result = self.request('payrolls', 'POST', payload, self.token)
        self.assertEqual(code, 422, result)
        _, after = self.request('payrolls', token=self.token)
        self.assertEqual(len(before['payrolls']), len(after['payrolls']))
        code, result = self.request(f"incidences/{incidence['id']}", 'PATCH', {'type': 'bonus'}, self.token)
        self.assertEqual(code, 200, result)
        code, result = self.request('payrolls', 'POST', payload, self.token)
        self.assertEqual(code, 201, result)
        self.assertEqual(float(result['payroll']['total_net']), 1)
        self.assertEqual(result['incidence_ids'], [incidence['id']])

    def test_record_filters_and_preview_validate_dates(self):
        for route in [
            'attendance&period_start=2026-07-15&period_end=2026-07-01',
            'incidences&employee_id=invalid',
            'payrolls/preview&period_start=2026-07-01&period_end=2026-08-31',
            'payrolls/preview&period_start=2026-02-30&period_end=2026-03-01',
        ]:
            with self.subTest(route=route):
                code, result = self.request(route, token=self.token)
                self.assertEqual(code, 422, result)

    def test_hire_date_cannot_exclude_existing_records(self):
        for kind in ['attendance', 'incidence', 'payroll']:
            with self.subTest(kind=kind):
                employee = self.new_employee()
                if kind == 'attendance':
                    self.new_attendance(employee['id'])
                    allowed_date, invalid_date = '2026-07-01', '2026-07-02'
                elif kind == 'incidence':
                    self.new_incidence(employee['id'])
                    allowed_date, invalid_date = '2026-07-01', '2026-07-02'
                else:
                    code, result = self.request('payrolls', 'POST', self.payroll_data(employee['id']), self.token)
                    self.assertEqual(code, 201, result)
                    allowed_date, invalid_date = '2026-08-15', '2026-08-16'
                code, result = self.request(f"employees/{employee['id']}", 'PATCH', {'hire_date': invalid_date}, self.token)
                self.assertEqual(code, 409, result)
                code, result = self.request('employees', token=self.token)
                saved = next(row for row in result['employees'] if row['id'] == employee['id'])
                self.assertEqual(saved['hire_date'], employee['hire_date'])
                code, result = self.request(f"employees/{employee['id']}", 'PATCH', {'hire_date': allowed_date}, self.token)
                self.assertEqual(code, 200, result)
                self.assertEqual(result['employee']['hire_date'], allowed_date)

    def test_reports_export_employee_and_historical_payroll(self):
        employee = self.new_employee(full_name='=Formula de prueba')
        rows = self.export_csv('reports/employees')
        row = next(row for row in rows if employee['employee_code'] in row)
        self.assertIn("'=Formula de prueba", row)
        code, result = self.request('payrolls', 'POST', self.payroll_data(employee['id']), self.token)
        self.assertEqual(code, 201, result)
        payroll_id = result['payroll']['id']
        self.request(f"employees/{employee['id']}", 'PATCH', {'full_name': 'Nombre posterior', 'monthly_salary': '25000.00'}, self.token)
        rows = self.export_csv(f'reports/payrolls/{payroll_id}')
        row = next(row for row in rows if employee['employee_code'] in row)
        self.assertIn("'=Formula de prueba", row)
        self.assertNotIn('Nombre posterior', row)
        self.assertEqual(self.export_csv(f'payrolls/{payroll_id}/export'), rows)
        code, dashboard = self.request('reports/dashboard', token=self.token)
        self.assertEqual(code, 200, dashboard)
        self.assertIn('pending_runs', dashboard['stats'])
        self.assertIn('paid_total', dashboard['stats'])
        self.assertEqual(len(dashboard['monthly_payments']), 6)

    def test_user_create_update_and_public_fields(self):
        user, credentials = self.new_user(email='  ' + uuid.uuid4().hex.upper() + '@EXAMPLE.TEST  ')
        self.assertEqual(user['email'], credentials['email'].strip().lower())
        self.assertEqual(user['status'], 'active')
        code, users = self.request('users', token=self.token)
        self.assertEqual(code, 200, users)
        self.assertIn(user, users['users'])
        for saved in users['users']:
            self.assertNotIn('password', saved)
            self.assertNotIn('password_hash', saved)
            self.assertNotIn('token_hash', saved)
        code, session = self.request('auth/login', 'POST', {'email': user['email'], 'password': credentials['password']})
        self.assertEqual(code, 200, session)
        code, changed = self.request(f"users/{user['id']}", 'PATCH', {'name': 'Nombre corregido'}, self.token)
        self.assertEqual(code, 200, changed)
        self.assertEqual(changed['user']['name'], 'Nombre corregido')
        self.assertEqual(changed['user']['role'], 'viewer')
        self.assertEqual(changed['user']['status'], 'active')
        self.assertEqual(self.request('auth/me', token=session['token'])[0], 200)
        code, duplicate = self.request('users', 'POST', {**credentials, 'email': user['email'].upper()}, self.token)
        self.assertEqual(code, 409, duplicate)
        other, _ = self.new_user()
        code, duplicate = self.request(f"users/{other['id']}", 'PATCH', {'email': user['email']}, self.token)
        self.assertEqual(code, 409, duplicate)
        self.assertEqual(self.request(f"users/{user['id']}", 'DELETE', token=self.token)[0], 405)
        self.assertEqual(self.request('users/99999999', 'PATCH', {'name': 'No existe'}, self.token)[0], 404)

    def test_user_sensitive_changes_revoke_sessions_and_inactive_login(self):
        user, credentials = self.new_user()
        route = f"users/{user['id']}"

        def login(password=credentials['password']):
            code, result = self.request('auth/login', 'POST', {'email': user['email'], 'password': password})
            self.assertEqual(code, 200, result)
            return result['token']

        first, second = login(), login()
        code, changed = self.request(route, 'PATCH', {'role': 'admin'}, self.token)
        self.assertEqual(code, 200, changed)
        self.assertEqual(changed['user']['role'], 'admin')
        for token in [first, second]:
            self.assertEqual(self.request('auth/me', token=token)[0], 401)
        active = login()
        code, changed = self.request(route, 'PATCH', {'status': 'inactive'}, self.token)
        self.assertEqual(code, 200, changed)
        self.assertEqual(self.request('auth/me', token=active)[0], 401)
        self.assertEqual(self.request('auth/login', 'POST', {'email': user['email'], 'password': credentials['password']})[0], 401)
        code, changed = self.request(route, 'PATCH', {'status': 'active'}, self.token)
        self.assertEqual(code, 200, changed)
        active = login()
        new_password = 'Usuario2026!Renovada'
        code, changed = self.request(route, 'PATCH', {'password': new_password}, self.token)
        self.assertEqual(code, 200, changed)
        self.assertEqual(self.request('auth/me', token=active)[0], 401)
        self.assertEqual(self.request('auth/login', 'POST', {'email': user['email'], 'password': credentials['password']})[0], 401)
        self.assertEqual(self.request('auth/me', token=login(new_password))[0], 200)

    def test_user_validation_and_self_protection_have_no_effects(self):
        user, payload = self.new_user()
        before = self.audit(action='user.created')['total']
        invalid = [
            {'name': ''}, {'name': 'Nombre\x00'}, {'email': 'no-es-correo'},
            {'email': 'á@example.test'}, {'role': 'owner'}, {'status': 'blocked'},
            {'password': 'corta'}, {'password': 'a' * 73}, {'password': 'á' * 37},
            {'password': 'Password\x00Pruebas'}, {'unexpected': True},
        ]
        for changes in invalid:
            with self.subTest(changes=changes):
                code, result = self.request('users', 'POST', {**payload, 'email': uuid.uuid4().hex + '@example.test', **changes}, self.token)
                self.assertEqual(code, 422, result)
        self.assertEqual(self.audit(action='user.created')['total'], before)
        for changes in [{'status': None}, {'role': None}, {'password': ''}, {'unknown': True}]:
            code, result = self.request(f"users/{user['id']}", 'PATCH', changes, self.token)
            self.assertEqual(code, 422, result)
        code, profile = self.request('auth/me', token=self.token)
        self.assertEqual(code, 200, profile)
        own_route = f"users/{profile['user']['id']}"
        for changes in [{'role': 'viewer'}, {'status': 'inactive'}]:
            code, result = self.request(own_route, 'PATCH', changes, self.token)
            self.assertEqual(code, 409, result)
        self.assertEqual(self.request('auth/me', token=self.token)[1]['user'], profile['user'])

    def test_users_and_audit_require_admin(self):
        code, viewer = self.request('auth/login', 'POST', {'email': 'viewer@example.test', 'password': 'Viewer2026!'})
        self.assertEqual(code, 200, viewer)
        for route, method, payload in [('users', 'GET', None), ('users', 'POST', {}), ('users/1', 'PATCH', {'name': 'No autorizado'}), ('audit', 'GET', None)]:
            with self.subTest(route=route, method=method):
                code, result = self.request(route, method, payload, viewer['token'])
                self.assertEqual(code, 403, result)
        self.assertEqual(self.request('audit', 'POST', {}, self.token)[0], 405)

    def test_audit_filters_pagination_privacy_and_failed_writes(self):
        before = self.audit(action='user.created')['total']
        user, payload = self.new_user()
        other, _ = self.new_user()
        logs = self.audit(action='user.created', entity='user', per_page=1)
        self.assertEqual(logs['total'], before + 2)
        self.assertEqual((logs['page'], logs['per_page'], len(logs['items'])), (1, 1, 1))
        self.assertEqual(logs['items'][0]['entity_id'], other['id'])
        second_page = self.audit(action='user.created', entity='user', per_page=1, page=2)
        self.assertEqual(second_page['items'][0]['entity_id'], user['id'])
        entry = second_page['items'][0]
        self.assertEqual(set(entry), {'id', 'actor_id', 'actor_name', 'action', 'entity', 'entity_id', 'summary', 'created_at'})
        actor = self.request('auth/me', token=self.token)[1]['user']
        self.assertEqual(entry['actor_id'], actor['id'])
        self.assertEqual(entry['actor_name'], actor['name'])
        self.assertTrue(entry['summary'])
        day = entry['created_at'][:10]
        filtered = self.audit(action='user.created', actor_id=actor['id'], date_from=day, date_to=day, per_page=100)
        self.assertIn(entry, filtered['items'])
        serialized = json.dumps(self.audit(per_page=100), ensure_ascii=False)
        for secret in [payload['password'], self.token, hashlib.sha256(self.token.encode()).hexdigest(), 'password_hash', 'token_hash']:
            self.assertNotIn(secret, serialized)
        total = self.audit()['total']
        self.assertEqual(self.request('users', 'POST', payload, self.token)[0], 409)
        self.assertEqual(self.request(f"users/{user['id']}", 'PATCH', {'role': 'unknown'}, self.token)[0], 422)
        self.assertEqual(self.audit()['total'], total)
        self.assertEqual(self.audit(action='user.created', actor_id=99999999)['items'], [])

    def test_audit_filter_validation(self):
        for filters in [{'page': 0}, {'per_page': 0}, {'per_page': 101}, {'actor_id': -1}, {'page': '1e2'}, {'date_from': '2026-02-30'}, {'date_from': '2026-10-14', 'date_to': '2026-10-01'}]:
            with self.subTest(filters=filters):
                code, result = self.request('audit&' + urlencode(filters), token=self.token)
                self.assertEqual(code, 422, result)

    def test_audit_failure_rolls_back_user_and_employee_changes(self):
        users_before = self.request('users', token=self.token)[1]
        employees_before = self.request('employees', token=self.token)[1]
        audit_before = self.audit()['total']
        self.fixture('fail-audit-writes')
        try:
            code, result = self.request('users', 'POST', {'name': 'No persistir', 'email': uuid.uuid4().hex + '@example.test', 'password': 'Rollback2026!Seguro'}, self.token)
            self.assertEqual(code, 503, result)
            code, result = self.request('employees', 'POST', {'employee_code': 'F-' + uuid.uuid4().hex[:10], 'full_name': 'No persistir', 'department': 'Pruebas', 'position': 'Analista', 'monthly_salary': '18000.00', 'hire_date': '2024-01-01'}, self.token)
            self.assertEqual(code, 503, result)
        finally:
            self.fixture('restore-audit-writes')
        self.assertEqual(self.request('users', token=self.token)[1], users_before)
        self.assertEqual(self.request('employees', token=self.token)[1], employees_before)
        self.assertEqual(self.audit()['total'], audit_before)

    def test_audit_tracks_business_mutations_once_and_preserves_actor_name(self):
        actor, credentials = self.new_user(name='Administrador original', role='admin')
        code, session = self.request('auth/login', 'POST', {'email': actor['email'], 'password': credentials['password']})
        self.assertEqual(code, 200, session)
        code = 'A-' + uuid.uuid4().hex[:10]
        payload = {'employee_code': code, 'full_name': 'Persona auditada', 'department': 'Pruebas', 'position': 'Analista', 'monthly_salary': '18000.00', 'hire_date': '2024-01-01'}
        status, saved = self.request('employees', 'POST', payload, session['token'])
        self.assertEqual(status, 201, saved)
        employee_id = saved['employee']['id']
        events = self.audit(actor_id=actor['id'], action='employee.created')['items']
        self.assertEqual(len(events), 1)
        self.assertEqual(events[0]['entity_id'], employee_id)
        self.assertEqual(events[0]['actor_name'], 'Administrador original')
        self.assertEqual(self.request(f"users/{actor['id']}", 'PATCH', {'name': 'Administrador nuevo'}, self.token)[0], 200)
        self.assertEqual(self.audit(actor_id=actor['id'], action='employee.created')['items'], events)
        attendance = self.new_attendance(employee_id)
        incidence = self.new_incidence(employee_id)
        self.assertEqual(self.request(f'employees/{employee_id}', 'PATCH', {'full_name': 'Persona actualizada'}, self.token)[0], 200)
        self.assertEqual(self.request(f"attendance/{attendance['id']}", 'PATCH', {'status': 'paid_leave'}, self.token)[0], 200)
        self.assertEqual(self.request(f"incidences/{incidence['id']}", 'PATCH', {'amount': 300}, self.token)[0], 200)
        status, created = self.request('payrolls', 'POST', self.payroll_data(employee_id), self.token)
        self.assertEqual(status, 201, created)
        payroll_id = created['payroll']['id']
        for _ in range(2):
            self.assertEqual(self.request(f'payrolls/{payroll_id}/pay', 'POST', {}, self.token)[0], 200)
        paid_events = self.audit(action='payroll.paid', per_page=100)['items']
        self.assertEqual(sum(event['entity_id'] == payroll_id for event in paid_events), 1)
        for action, entity_id in [('employee.updated', employee_id), ('attendance.created', attendance['id']), ('attendance.updated', attendance['id']), ('incidence.created', incidence['id']), ('incidence.updated', incidence['id']), ('payroll.created', payroll_id)]:
            with self.subTest(action=action):
                self.assertTrue(any(event['entity_id'] == entity_id for event in self.audit(action=action, per_page=100)['items']))

    def test_z_login_rate_limit(self):
        payload = {'email': 'throttle@example.test', 'password': 'incorrecta'}
        responses = [self.request('auth/login', 'POST', payload)[0] for _ in range(6)]
        self.assertEqual(responses[:5], [401] * 5)
        self.assertEqual(responses[5], 429)


class MigrationTests(unittest.TestCase):
    def test_legacy_cancellation_migration_preserves_data_and_is_idempotent(self):
        env = {**os.environ, 'NOMINA_DB_NAME': 'nomina_test_' + uuid.uuid4().hex[:12]}

        def php(*arguments):
            result = subprocess.run([PHP, *arguments], cwd=ROOT, env=env, capture_output=True, text=True, encoding='utf-8')
            self.assertEqual(result.returncode, 0, result.stderr)
            return result.stdout

        php('scripts/install.php', '--demo')
        self.addCleanup(php, 'tests/database_fixture.php', 'cleanup')
        php('tests/database_fixture.php', 'legacy-cancellation-schema')
        before = json.loads(php('tests/database_fixture.php', 'migration-snapshot'))
        old_schema = before.pop('schema')
        self.assertNotIn('cancelled_at', old_schema['columns'])
        self.assertNotIn("'cancelled'", old_schema['columns']['status'])
        self.assertNotIn('status', old_schema['user_columns'])
        self.assertNotIn('actor_name', old_schema['audit_columns'])
        self.assertNotIn('summary', old_schema['audit_columns'])
        self.assertEqual(int(old_schema['indexes']['uq_applied_incidence']), 0)
        self.assertEqual(len(before['payrolls']), 1)
        self.assertEqual(len(before['payroll_items']), 1)
        self.assertEqual(len(before['payroll_incidences']), 1)
        # Migrar dos veces debe conservar contraseñas, importes, snapshots y vínculos.
        for attempt in range(2):
            with self.subTest(attempt=attempt + 1):
                php('scripts/install.php', '--migrate-only')
                after = json.loads(php('tests/database_fixture.php', 'migration-snapshot'))
                schema = after.pop('schema')
                for user in after['users']:
                    self.assertEqual(user.pop('status'), 'active')
                for entry in after['audit_logs']:
                    self.assertEqual(entry.pop('actor_name'), 'Usuario histórico')
                    self.assertEqual(entry.pop('summary'), 'Registro histórico')
                self.assertEqual(after, before)
                self.assertIn("'inactive'", schema['user_columns']['status'])
                self.assertIn('actor_name', schema['audit_columns'])
                self.assertIn('summary', schema['audit_columns'])
                self.assertIn("'cancelled'", schema['columns']['status'])
                for column in ['cancelled_at', 'cancelled_by', 'cancellation_reason']:
                    self.assertIn(column, schema['columns'])
                self.assertNotIn('uq_applied_incidence', schema['indexes'])
                self.assertEqual(int(schema['indexes']['idx_applied_incidence']), 1)
                self.assertIn('fk_payroll_canceller', schema['foreign_keys'])


if __name__ == '__main__':
    unittest.main(verbosity=2)
