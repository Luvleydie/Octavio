"""GraphQL HTTP integration, isolated in a disposable nomina_test_* database."""
import json
import shutil
import subprocess
import unittest
import uuid
from urllib.error import HTTPError
from urllib.request import Request, urlopen

import test_api


class GraphQLTests(unittest.TestCase):
    setUpClass = classmethod(test_api.ApiTests.setUpClass.__func__)
    cleanup = classmethod(test_api.ApiTests.cleanup.__func__)
    request = classmethod(test_api.ApiTests.request.__func__)
    fixture = classmethod(test_api.ApiTests.fixture.__func__)

    def gql(self, query, variables=None, token=None, operation=None):
        payload = {'query': query, 'variables': variables or {}}
        if operation:
            payload['operationName'] = operation
        return self.raw_gql(payload, token)

    def raw_gql(self, payload, token=None, method='POST'):
        headers = {'Content-Type': 'application/json', 'Accept': 'application/json'}
        if token:
            headers['Authorization'] = 'Bearer ' + token
        req = Request(self.url.split('index.php')[0] + 'graphql.php', data=json.dumps(payload).encode() if method == 'POST' else None, headers=headers, method=method)
        try:
            response = urlopen(req, timeout=8)
        except HTTPError as error:
            response = error
        with response:
            return response.status, json.loads(response.read())

    def success(self, query, variables=None, token=None, operation=None):
        code, result = self.gql(query, variables, token, operation)
        self.assertEqual(code, 200, result)
        self.assertNotIn('errors', result, result)
        return result['data']

    def employee(self):
        code = 'G-' + uuid.uuid4().hex[:10]
        data = {'employee_code': code, 'full_name': 'Empleado GraphQL', 'department': 'Pruebas', 'position': 'Analista', 'monthly_salary': '18000.00', 'hire_date': '2024-01-01'}
        return self.success('mutation($input: EmployeeCreateInput!) { createEmployee(input: $input) { id full_name monthly_salary } }', {'input': data}, self.token)['createEmployee']

    def test_selection_fragments_aliases_and_named_operation(self):
        result = self.success('query Other { health { database } } query Chosen { one: health { ...Status } two: health { database } } fragment Status on Health { status }', operation='Chosen')
        self.assertEqual(result, {'one': {'status': 'ok'}, 'two': {'database': 'connected'}})
        schema = self.success('{ __schema { queryType { name } mutationType { name } } }')
        self.assertEqual(schema['__schema']['mutationType']['name'], 'Mutation')

    def test_null_employee_status_does_not_reactivate_employee(self):
        employee_id = self.employee()['id']
        update = 'mutation($id: ID!, $input: EmployeeUpdateInput!) { updateEmployee(id: $id, input: $input) { id status full_name } }'
        self.success(update, {'id': employee_id, 'input': {'status': 'inactive'}}, self.token)

        _, rejected = self.gql(update, {'id': employee_id, 'input': {'status': None}}, self.token)
        self.assertEqual(rejected['errors'][0]['extensions']['status'], 422, rejected)
        employees = self.success('{ employees { id status } }', token=self.token)['employees']
        saved = next(employee for employee in employees if employee['id'] == employee_id)
        self.assertEqual(saved['status'], 'inactive')

        # Omitting status still preserves it during an unrelated partial update.
        changed = self.success(update, {'id': employee_id, 'input': {'full_name': 'Persona inactiva'}}, self.token)
        self.assertEqual(changed['updateEmployee']['status'], 'inactive')
        self.assertEqual(changed['updateEmployee']['full_name'], 'Persona inactiva')

    def test_users_and_audit_schema_and_permissions(self):
        schema = self.success('{ __schema { queryType { fields { name } } mutationType { fields { name } } } }')['__schema']
        queries = {field['name'] for field in schema['queryType']['fields']}
        mutations = {field['name'] for field in schema['mutationType']['fields']}
        self.assertTrue({'users', 'auditLogs'} <= queries)
        self.assertTrue({'createUser', 'updateUser'} <= mutations)
        types = self.success('{ __type(name: "User") { fields { name } } }')['__type']
        fields = {field['name'] for field in types['fields']}
        self.assertTrue({'id', 'name', 'email', 'role', 'status'} <= fields)
        self.assertTrue({'password', 'password_hash', 'token_hash'}.isdisjoint(fields))
        viewer = self.success('mutation { login(email: "viewer@example.test", password: "Viewer2026!") { token } }')['login']['token']
        for token, expected in [(None, 401), (viewer, 403)]:
            for query in ['{ users { id } }', '{ auditLogs { total } }', 'mutation { createUser(input: {name: "Sin permisos", email: "sin-permiso@example.test", password: "Pruebas2026!Segura"}) { id } }', 'mutation { updateUser(id: 1, input: {name: "Sin permisos"}) { id } }']:
                with self.subTest(token=bool(token), query=query):
                    _, rejected = self.gql(query, token=token)
                    self.assertEqual(rejected['errors'][0]['extensions']['status'], expected, rejected)

    def test_user_lifecycle_audit_and_revocation(self):
        email = uuid.uuid4().hex + '@example.test'
        password = 'GraphQL2026!Original'
        create = 'mutation($input: UserCreateInput!) { createUser(input: $input) { id name email role status created_at } }'
        update = 'mutation($id: ID!, $input: UserUpdateInput!) { updateUser(id: $id, input: $input) { id name email role status } }'
        user = self.success(create, {'input': {'name': 'Cuenta GraphQL', 'email': email, 'password': password}}, self.token)['createUser']
        self.assertEqual((user['role'], user['status']), ('viewer', 'active'))
        login = 'mutation($email: String!, $password: String!) { login(email: $email, password: $password) { token user { status } } }'
        session = self.success(login, {'email': email, 'password': password})['login']
        changed = self.success(update, {'id': user['id'], 'input': {'status': 'inactive'}}, self.token)['updateUser']
        self.assertEqual(changed['status'], 'inactive')
        _, expired = self.gql('{ me { authenticated } }', token=session['token'])
        self.assertEqual(expired['errors'][0]['extensions']['status'], 401)
        _, disabled = self.gql(login, {'email': email, 'password': password})
        self.assertEqual(disabled['errors'][0]['extensions']['status'], 401)
        # Null is different from omitting a field in a partial update.
        _, rejected = self.gql(update, {'id': user['id'], 'input': {'status': None}}, self.token)
        self.assertEqual(rejected['errors'][0]['extensions']['status'], 422)
        changed = self.success(update, {'id': user['id'], 'input': {'name': 'Nombre actualizado'}}, self.token)['updateUser']
        self.assertEqual(changed['status'], 'inactive')
        self.success(update, {'id': user['id'], 'input': {'status': 'active', 'role': 'admin'}}, self.token)
        session = self.success(login, {'email': email, 'password': password})['login']
        # The next mutation must recheck the revoked session inside the same request.
        mutation = 'mutation($id: ID!) { first: updateUser(id: $id, input: {password: "GraphQL2026!Actualizada"}) { id } second: updateUser(id: $id, input: {name: "No se debe guardar"}) { id } }'
        _, revoked = self.gql(mutation, {'id': user['id']}, session['token'])
        self.assertEqual(revoked['data']['first']['id'], user['id'])
        self.assertEqual(revoked['errors'][0]['extensions']['status'], 401, revoked)
        users = self.success('{ users { id name } }', token=self.token)['users']
        self.assertEqual(next(row['name'] for row in users if row['id'] == user['id']), 'Nombre actualizado')
        audit = self.success('query($filter: AuditFilter) { auditLogs(filter: $filter) { items { id actor_id actor_name action entity entity_id summary created_at } total page per_page } }', {'filter': {'action': 'user.created', 'entity': 'user', 'per_page': 1}}, self.token)['auditLogs']
        self.assertEqual(audit['items'][0]['entity_id'], user['id'])
        self.assertEqual((audit['page'], audit['per_page']), (1, 1))
        self.assertNotIn(password, json.dumps(audit))
        _, invalid = self.gql('{ auditLogs(filter: {per_page: 101}) { total } }', token=self.token)
        self.assertEqual(invalid['errors'][0]['extensions']['status'], 422)

    def test_user_rejected_changes_do_not_create_audit_events(self):
        me = self.success('{ me { user { id role status } } auditLogs { total } }', token=self.token)
        user_id = me['me']['user']['id']
        for input in [{'role': 'viewer'}, {'status': 'inactive'}]:
            _, invalid = self.gql('mutation($id: ID!, $input: UserUpdateInput!) { updateUser(id: $id, input: $input) { id } }', {'id': user_id, 'input': input}, self.token)
            self.assertEqual(invalid['errors'][0]['extensions']['status'], 409)
        for password in ['corta', 'x' * 73, 'á' * 37, 'Password\x00Pruebas']:
            _, invalid = self.gql('mutation($input: UserCreateInput!) { createUser(input: $input) { id } }', {'input': {'name': 'Rechazado', 'email': uuid.uuid4().hex + '@example.test', 'password': password}}, self.token)
            self.assertEqual(invalid['errors'][0]['extensions']['status'], 422)
        after = self.success('{ me { user { id role status } } auditLogs { total } }', token=self.token)
        self.assertEqual(after, me)

    def test_invalid_document_and_variables_have_no_side_effects(self):
        for payload in [
            {'query': '{ doesNotExist }'},
            {'query': 'query { employees('},
            {'query': '{ health { status } }', 'variables': [1]},
            {'query': '{ health { status } }', 'operationName': 7},
            [{'query': '{ health { status } }'}],
        ]:
            _, result = self.raw_gql(payload, self.token)
            self.assertIn('errors', result)
        _, invalid = self.gql('mutation { createEmployee(input: { employee_code: "BAD" }) { id } }', token=self.token)
        self.assertIn('errors', invalid)
        code, _ = self.raw_gql({}, method='GET')
        self.assertEqual(code, 405)

    def test_authentication_permissions_and_revocation_in_one_request(self):
        _, denied = self.gql('{ employees { id } payrolls { id } }')
        self.assertTrue(all(e['extensions']['status'] == 401 for e in denied['errors']))
        login = self.success('mutation($email: String!, $password: String!) { login(email: $email, password: $password) { token user { role } } }', {'email': 'viewer@example.test', 'password': 'Viewer2026!'})['login']
        token = login['token']
        data = self.success('{ employees { id full_name } }', token=token)
        self.assertTrue(data['employees'])
        _, forbidden = self.gql('mutation { payPayroll(id: 1) { payroll { id } } }', token=token)
        self.assertEqual(forbidden['errors'][0]['extensions']['status'], 403)
        _, result = self.gql('mutation { logout { authenticated } later: revokeOtherSessions { message } }', token=token)
        self.assertFalse(result['data']['logout']['authenticated'])
        self.assertEqual(result['errors'][0]['extensions']['status'], 401)

    def test_full_payroll_flow_and_history(self):
        employee = self.employee()
        employee_id = employee['id']
        changed = self.success('mutation($id: ID!, $input: EmployeeUpdateInput!) { updateEmployee(id: $id, input: $input) { full_name } }', {'id': employee_id, 'input': {'full_name': 'Persona GraphQL'}}, self.token)
        self.assertEqual(changed['updateEmployee']['full_name'], 'Persona GraphQL')
        attendance = self.success('mutation($input: AttendanceCreateInput!) { createAttendance(input: $input) { id } }', {'input': {'employee_id': employee_id, 'work_date': '2026-05-01', 'status': 'present'}}, self.token)['createAttendance']
        self.success('mutation($id: ID!, $input: AttendanceUpdateInput!) { updateAttendance(id: $id, input: $input) { status } }', {'id': attendance['id'], 'input': {'status': 'paid_leave'}}, self.token)
        incidence = self.success('mutation($input: IncidenceCreateInput!) { createIncidence(input: $input) { id amount } }', {'input': {'employee_id': employee_id, 'occurrence_date': '2026-05-01', 'type': 'bonus', 'concept': 'Bono', 'amount': '500.00'}}, self.token)['createIncidence']
        self.success('mutation($id: ID!, $input: IncidenceUpdateInput!) { updateIncidence(id: $id, input: $input) { amount } }', {'id': incidence['id'], 'input': {'amount': 400}}, self.token)
        records = self.success('query($filter: RecordFilter) { attendance(filter: $filter) { id status } incidences(filter: $filter) { id amount } }', {'filter': {'employee_id': employee_id}}, self.token)
        self.assertEqual(len(records['attendance']), 1)
        preview = self.success('query($id: ID!) { payrollPreview(period_start: "2026-05-01", period_end: "2026-05-15", employee_id: $id) { totals { net } items { can_create } } }', {'id': employee_id}, self.token)['payrollPreview']
        self.assertEqual(preview['totals']['net'], 1000)
        inputs = {'period_start': '2026-05-01', 'period_end': '2026-05-15', 'use_records': True, 'items': [{'employee_id': employee_id}]}
        mutation = 'mutation($input: PayrollInput!) { createPayroll(input: $input) { payroll { id total_net status } items { full_name net_pay } incidence_ids } }'
        created = self.success(mutation, {'input': inputs}, self.token)['createPayroll']
        self.assertEqual(created['payroll']['total_net'], 1000)
        self.assertEqual(created['incidence_ids'], [incidence['id']])
        payroll_id = created['payroll']['id']
        _, duplicate = self.gql(mutation, {'input': inputs}, self.token)
        self.assertEqual(duplicate['errors'][0]['extensions']['status'], 409)
        cancelled = self.success('mutation($id: ID!) { cancelPayroll(id: $id, reason: "Corregir captura") { payroll { status } } }', {'id': payroll_id}, self.token)
        self.assertEqual(cancelled['cancelPayroll']['payroll']['status'], 'cancelled')
        regenerated = self.success(mutation, {'input': inputs}, self.token)['createPayroll']
        paid = self.success('mutation($id: ID!) { payPayroll(id: $id) { payroll { status paid_at } } }', {'id': regenerated['payroll']['id']}, self.token)
        self.assertEqual(paid['payPayroll']['payroll']['status'], 'paid')
        data = self.success('query($id: ID!) { payroll(id: $id) { payroll { status } items { net_pay } } payments { id } dashboard { stats { paid_total } } }', {'id': payroll_id}, self.token)
        self.assertEqual(data['payroll']['payroll']['status'], 'cancelled')
        self.assertTrue(data['payments'])

    def test_account_password_and_sessions(self):
        # Esta cuenta y todas sus sesiones viven solamente en la base temporal.
        self.fixture('account')
        token = self.success('mutation { login(email: "account@example.test", password: "Account2026!Original") { token } }')['login']['token']
        me = self.success('{ me { user { name } } sessions { id current } }', token=token)
        self.assertEqual(me['me']['user']['name'], 'Cuenta de pruebas')
        self.success('mutation { revokeOtherSessions { revoked_count } }', token=token)
        self.success('mutation { changePassword(current_password: "Account2026!Original", new_password: "Account2026!Changed") { authenticated } }', token=token)
        _, expired = self.gql('{ me { authenticated } }', token=token)
        self.assertEqual(expired['errors'][0]['extensions']['status'], 401)

    def test_frontend_graphql_documents(self):
        # Real client documents are executed against HTTP, catching schema/selection drift.
        if not shutil.which('node'):
            self.skipTest('Node no está instalado; se omite la construcción de documentos de la interfaz.')
        script = """
require('./public/assets/graphql.js');
const cases = ['health','auth/me','auth/sessions','employees','attendance','incidences','payrolls','payments','dashboard','users','audit'];
const docs = cases.map(route => NominaGraphQL.build(route));
docs.push(NominaGraphQL.build('payrolls/preview', {query:{period_start:'2026-01-01',period_end:'2026-01-15'}}));
process.stdout.write(JSON.stringify(docs));
"""
        output = subprocess.run(['node', '-e', script], cwd=test_api.ROOT, capture_output=True, text=True, check=True)
        for document in json.loads(output.stdout):
            self.success(document['query'], document['variables'], self.token, document['operationName'])

    def test_frontend_user_mutation_documents(self):
        if not shutil.which('node'):
            self.skipTest('Node no está instalado.')

        def build(route, options):
            script = "require('./public/assets/graphql.js'); const input = JSON.parse(process.argv[1]); process.stdout.write(JSON.stringify(NominaGraphQL.build(input.route, input.options)));"
            output = subprocess.run(['node', '-e', script, json.dumps({'route': route, 'options': options})], cwd=test_api.ROOT, capture_output=True, text=True, check=True)
            return json.loads(output.stdout)

        document = build('users', {'method': 'POST', 'body': {'name': 'Formulario de usuario', 'email': uuid.uuid4().hex + '@example.test', 'password': 'Formulario2026!Seguro', 'role': 'viewer', 'status': 'active'}})
        created = self.success(document['query'], document['variables'], self.token, document['operationName'])['result']
        document = build('users/' + created['id'], {'method': 'PATCH', 'body': {'status': 'inactive'}})
        changed = self.success(document['query'], document['variables'], self.token, document['operationName'])['result']
        self.assertEqual(changed['status'], 'inactive')

    def test_limits_and_login_throttling_with_aliases(self):
        _, complex_query = self.gql('query { ' + ' '.join(f'h{i}: health {{ status }}' for i in range(510)) + ' }')
        self.assertIn('errors', complex_query)
        self.assertNotIn('data', complex_query)
        email = uuid.uuid4().hex + '@example.test'
        aliases = ' '.join(f'login{i}: login(email: $email, password: "Incorrecta2026!") {{ token }}' for i in range(7))
        _, limited = self.gql('mutation($email: String!) { ' + aliases + ' }', {'email': email})
        self.assertEqual([e['extensions']['status'] for e in limited['errors']], [401] * 5 + [429] * 2)

    def test_validation_before_mutations_and_invalid_money(self):
        before = self.success('{ employees { id } }', token=self.token)['employees']
        data = {'employee_code': 'G-' + uuid.uuid4().hex[:10], 'full_name': 'No debe guardarse', 'department': 'Pruebas', 'position': 'Analista', 'monthly_salary': '18000.00', 'hire_date': '2024-01-01'}
        _, invalid = self.gql('mutation($input: EmployeeCreateInput!) { createEmployee(input: $input) { id } unknownMutation }', {'input': data}, self.token)
        self.assertIn('errors', invalid)
        for value in ['0.001', '-5.00', '1e3']:
            data['monthly_salary'] = value
            _, invalid = self.gql('mutation($input: EmployeeCreateInput!) { createEmployee(input: $input) { id } }', {'input': data}, self.token)
            self.assertIn('errors', invalid)
        after = self.success('{ employees { id } }', token=self.token)['employees']
        self.assertEqual(before, after)


if __name__ == '__main__':
    unittest.main(verbosity=2)
