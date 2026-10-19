"""Regresiones de acceso de Usuarios en una base temporal nomina_test_*."""
import unittest
import uuid

import test_api


class UserSecurityTests(unittest.TestCase):
    setUpClass = classmethod(test_api.ApiTests.setUpClass.__func__)
    cleanup = classmethod(test_api.ApiTests.cleanup.__func__)
    request = classmethod(test_api.ApiTests.request.__func__)
    fixture = classmethod(test_api.ApiTests.fixture.__func__)
    new_user = test_api.ApiTests.new_user
    audit = test_api.ApiTests.audit

    def login_user(self, email, password):
        code, session = self.request('auth/login', 'POST', {'email': email, 'password': password})
        self.assertEqual(code, 200, session)
        return session['token']

    def test_email_change_revokes_all_sessions_and_moves_login(self):
        user, credentials = self.new_user()
        tokens = [self.login_user(user['email'], credentials['password']) for _ in range(2)]
        new_email = uuid.uuid4().hex + '@example.test'
        code, result = self.request(f"users/{user['id']}", 'PATCH', {'email': new_email}, self.token)
        self.assertEqual(code, 200, result)
        self.assertEqual(result['user']['email'], new_email)
        for token in tokens:
            self.assertEqual(self.request('auth/me', token=token)[0], 401)
        self.assertEqual(self.request('auth/login', 'POST', {
            'email': user['email'], 'password': credentials['password']})[0], 401)
        current = self.login_user(new_email, credentials['password'])
        self.assertEqual(self.request('auth/me', token=current)[1]['user']['id'], user['id'])

    def test_reactivation_does_not_restore_revoked_sessions(self):
        user, credentials = self.new_user()
        tokens = [self.login_user(user['email'], credentials['password']) for _ in range(2)]
        route = f"users/{user['id']}"
        for status in ['inactive', 'active']:
            code, result = self.request(route, 'PATCH', {'status': status}, self.token)
            self.assertEqual(code, 200, result)
            for token in tokens:
                self.assertEqual(self.request('auth/me', token=token)[0], 401)
        current = self.login_user(user['email'], credentials['password'])
        code, sessions = self.request('auth/sessions', token=current)
        self.assertEqual(code, 200, sessions)
        self.assertEqual(len(sessions['sessions']), 1)
        self.assertTrue(sessions['sessions'][0]['current'])

    def test_audit_failure_rolls_back_password_and_session_revocation(self):
        user, credentials = self.new_user()
        tokens = [self.login_user(user['email'], credentials['password']) for _ in range(2)]
        users_before = self.request('users', token=self.token)[1]
        audit_before = self.audit()['total']
        self.fixture('fail-audit-writes')
        try:
            code, result = self.request(f"users/{user['id']}", 'PATCH', {
                'name': 'Cambio que debe revertirse',
                'email': uuid.uuid4().hex + '@example.test',
                'password': 'Cambio2026!NoPersistir',
                'role': 'admin',
                'status': 'inactive',
            }, self.token)
            self.assertEqual(code, 503, result)
        finally:
            self.fixture('restore-audit-writes')
        self.assertEqual(self.request('users', token=self.token)[1], users_before)
        self.assertEqual(self.audit()['total'], audit_before)
        for token in tokens:
            code, profile = self.request('auth/me', token=token)
            self.assertEqual(code, 200, profile)
            self.assertEqual(profile['user'], user)
        self.login_user(user['email'], credentials['password'])


if __name__ == '__main__':
    unittest.main(verbosity=2)
