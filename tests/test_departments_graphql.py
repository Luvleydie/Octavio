"""Contrato HTTP de Departamentos en una base de datos temporal."""
import json
import shutil
import subprocess
import unittest
import uuid

import test_api
import test_graphql


class DepartmentGraphQLTests(unittest.TestCase):
    setUpClass = classmethod(test_api.ApiTests.setUpClass.__func__)
    cleanup = classmethod(test_api.ApiTests.cleanup.__func__)
    request = classmethod(test_api.ApiTests.request.__func__)
    fixture = classmethod(test_api.ApiTests.fixture.__func__)
    gql = test_graphql.GraphQLTests.gql
    raw_gql = test_graphql.GraphQLTests.raw_gql
    success = test_graphql.GraphQLTests.success

    fields = 'id name status employee_count active_employee_count created_at'
    create = 'mutation($input: DepartmentCreateInput!) { createDepartment(input: $input) { ' + fields + ' } }'
    update = 'mutation($id: ID!, $input: DepartmentUpdateInput!) { updateDepartment(id: $id, input: $input) { ' + fields + ' } }'

    def new_department(self, **changes):
        data = {'name': 'Área ' + uuid.uuid4().hex[:12], **changes}
        return self.success(self.create, {'input': data}, self.token)['createDepartment']

    def assert_error(self, query, variables, status, token):
        _, result = self.gql(query, variables, token)
        self.assertEqual(result['errors'][0]['extensions']['status'], status, result)
        return result

    def test_contract_and_permissions(self):
        schema = self.success('{ __schema { queryType { fields { name } } mutationType { fields { name } } } }')['__schema']
        self.assertIn('departments', {field['name'] for field in schema['queryType']['fields']})
        self.assertTrue({'createDepartment', 'updateDepartment'} <= {field['name'] for field in schema['mutationType']['fields']})
        fields = self.success('{ __type(name: "Department") { fields { name } } }')['__type']['fields']
        self.assertEqual({field['name'] for field in fields}, set(self.fields.split()))
        query = '{ departments { ' + self.fields + ' } }'
        self.assert_error(query, {}, 401, None)
        self.assert_error(self.create, {'input': {'name': 'Sin sesión'}}, 401, None)
        viewer = self.success('mutation { login(email: "viewer@example.test", password: "Viewer2026!") { token } }')['login']['token']
        self.success(query, token=viewer)
        self.assert_error(self.create, {'input': {'name': 'Sin permisos'}}, 403, viewer)
        self.assert_error(self.update, {'id': '1', 'input': {'name': 'Sin permisos'}}, 403, viewer)
        self.assertEqual(self.request('departments')[0], 401)
        self.assertEqual(self.request('departments', token=viewer)[0], 200)
        self.assertEqual(self.request('departments', 'POST', {'name': 'Sin permisos'}, viewer)[0], 403)

    def test_create_update_list_and_audit(self):
        department = self.new_department()
        self.assertEqual(department['status'], 'active')
        self.assertEqual((department['employee_count'], department['active_employee_count']), (0, 0))
        self.assertTrue(department['created_at'])
        renamed = department['name'] + ' renovada'
        changed = self.success(self.update, {'id': department['id'], 'input': {'name': renamed, 'status': 'inactive'}}, self.token)['updateDepartment']
        self.assertEqual((changed['name'], changed['status']), (renamed, 'inactive'))
        self.assertEqual(changed['id'], department['id'])
        self.assertEqual(changed['created_at'], department['created_at'])
        departments = self.success('{ departments { ' + self.fields + ' } }', token=self.token)['departments']
        self.assertIn(changed, departments)
        audit = self.success('query { auditLogs(filter: {entity: "department", per_page: 100}) { items { action entity_id } } }', token=self.token)['auditLogs']['items']
        actions = {event['action'] for event in audit if event['entity_id'] == department['id']}
        self.assertEqual(actions, {'department.created', 'department.updated'})

    def test_invalid_or_duplicate_input_does_not_change_department(self):
        department = self.new_department(status='inactive')
        other = self.new_department()
        self.assert_error(self.create, {'input': {'name': department['name']}}, 409, self.token)
        self.assert_error(self.update, {'id': department['id'], 'input': {'name': other['name']}}, 409, self.token)
        for input in [{}, {'name': ''}, {'name': 'x' * 101}, {'status': 'unknown'}, {'status': None}, {'name': None}]:
            with self.subTest(input=input):
                self.assert_error(self.update, {'id': department['id'], 'input': input}, 422, self.token)
        self.assert_error(self.update, {'id': '999999999', 'input': {'name': 'No existe'}}, 404, self.token)
        changed = self.success(self.update, {'id': department['id'], 'input': {'name': department['name'] + ' nueva'}}, self.token)['updateDepartment']
        self.assertEqual(changed['status'], 'inactive')

    def test_rest_and_graphql_share_the_catalog(self):
        name = 'REST ' + uuid.uuid4().hex[:12]
        code, created = self.request('departments', 'POST', {'name': name}, self.token)
        self.assertEqual(code, 201, created)
        department = created['department']
        code, changed = self.request(f"departments/{department['id']}", 'PATCH', {'status': 'inactive'}, self.token)
        self.assertEqual(code, 200, changed)
        self.assertEqual(changed['department']['status'], 'inactive')
        departments = self.success('{ departments { id name status } }', token=self.token)['departments']
        self.assertIn({'id': str(department['id']), 'name': name, 'status': 'inactive'}, departments)
        self.assertEqual(self.request(f"departments/{department['id']}", 'DELETE', token=self.token)[0], 405)

    def test_frontend_documents_match_the_schema(self):
        if not shutil.which('node'):
            self.skipTest('Node no está instalado.')

        def execute(route, options):
            script = "require('./public/assets/graphql.js'); const input = JSON.parse(process.argv[1]); process.stdout.write(JSON.stringify(NominaGraphQL.build(input.route, input.options)));"
            output = subprocess.run(['node', '-e', script, json.dumps({'route': route, 'options': options})], cwd=test_api.ROOT, capture_output=True, text=True, check=True)
            document = json.loads(output.stdout)
            return self.success(document['query'], document['variables'], self.token, document['operationName'])['result']

        created = execute('departments', {'method': 'POST', 'body': {'name': 'Formulario ' + uuid.uuid4().hex[:12]}})
        changed = execute('departments/' + created['id'], {'method': 'PATCH', 'body': {'status': 'inactive'}})
        self.assertEqual(changed['status'], 'inactive')
        self.assertIn(changed, execute('departments', {}))


if __name__ == '__main__':
    unittest.main(verbosity=2)
