<?php
declare(strict_types=1);

use GraphQL\Error\Error;
use GraphQL\Error\FormattedError;
use GraphQL\Executor\Executor;
use GraphQL\GraphQL;
use GraphQL\Language\AST\Node;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Schema;
use GraphQL\Utils\AST;
use GraphQL\Utils\BuildSchema;
use GraphQL\Validator\Rules\QueryComplexity;
use GraphQL\Validator\Rules\QueryDepth;

require_once dirname(__DIR__) . '/api.php';

function graphql_schema(): Schema
{
    // Los archivos describen los campos disponibles y los datos que aceptan.
    $definition = file_get_contents(__DIR__ . '/schema.graphql') . "\n"
        . file_get_contents(__DIR__ . '/mutations.graphql');
    return BuildSchema::build($definition, static function (array $config): array {
        if ($config['name'] === 'Money') {
            $parse = static fn(mixed $value): string => cents_to_decimal(money_to_cents($value));
            $config['serialize'] = $parse;
            $config['parseValue'] = $parse;
            $config['parseLiteral'] = static fn(Node $node, ?array $variables = null): string => $parse(AST::valueFromASTUntyped($node, $variables));
        }
        return $config;
    });
}

/** Un resolver conecta un campo GraphQL con la operación que devuelve sus datos. */
function graphql_resolve(mixed $source, array $args, mixed $context, ResolveInfo $info): mixed
{
    if ($info->parentType->name === 'Query') {
        return graphql_query($info->fieldName, $args);
    }
    if ($info->parentType->name === 'Mutation') {
        return graphql_mutation($info->fieldName, $args);
    }

    // Para un campo interno, como Employee.full_name, la librería lee la clave del arreglo.
    return Executor::defaultFieldResolver($source, $args, $context, $info);
}

/** Las consultas leen datos. Algunas respuestas REST incluyen una clave contenedora. */
function graphql_query(string $field, array $args): array
{
    switch ($field) {
        case 'health':
            return api_data('health', 'GET');
        case 'me':
            return api_data('auth/me', 'GET');
        case 'sessions':
            $result = api_data('auth/sessions', 'GET');
            return $result['sessions'];
        case 'employees':
            $result = api_data('employees', 'GET');
            return $result['employees'];
        case 'users':
            $result = api_data('users', 'GET');
            return $result['users'];
        case 'departments':
            $result = api_data('departments', 'GET');
            return $result['departments'];
        case 'auditLogs':
            return api_data('audit', 'GET', [], $args['filter'] ?? []);
        case 'attendance':
            $result = api_data('attendance', 'GET', [], $args['filter'] ?? []);
            return $result['attendance'];
        case 'incidences':
            $result = api_data('incidences', 'GET', [], $args['filter'] ?? []);
            return $result['incidences'];
        case 'payrolls':
            $result = api_data('payrolls', 'GET');
            return $result['payrolls'];
        case 'payroll':
            return api_data('payrolls/' . positive_id($args['id']), 'GET');
        case 'payrollPreview':
            return api_data('payrolls/preview', 'GET', [], $args);
        case 'payments':
            $result = api_data('payments', 'GET');
            return $result['payments'];
        case 'dashboard':
            return api_data('dashboard', 'GET');
        default:
            throw new ApiException(400, 'La consulta GraphQL no existe.');
    }
}

/** Las mutaciones usan los mismos servicios y permisos que POST/PATCH en REST. */
function graphql_mutation(string $field, array $args): array
{
    switch ($field) {
        case 'login':
            return api_data('auth/login', 'POST', $args);
        case 'logout':
            return api_data('auth/logout', 'POST');
        case 'changePassword':
            return api_data('auth/password', 'POST', $args);
        case 'revokeOtherSessions':
            return api_data('auth/sessions/revoke-others', 'POST');
        case 'createEmployee':
            $result = api_data('employees', 'POST', $args['input']);
            return $result['employee'];
        case 'createUser':
            $result = api_data('users', 'POST', $args['input']);
            return $result['user'];
        case 'updateUser':
            $result = api_data('users/' . positive_id($args['id']), 'PATCH', $args['input']);
            return $result['user'];
        case 'createDepartment':
            $result = api_data('departments', 'POST', $args['input']);
            return $result['department'];
        case 'updateDepartment':
            $result = api_data('departments/' . positive_id($args['id']), 'PATCH', $args['input']);
            return $result['department'];
        case 'updateEmployee':
            $result = api_data('employees/' . positive_id($args['id']), 'PATCH', $args['input']);
            return $result['employee'];
        case 'createAttendance':
            $result = api_data('attendance', 'POST', $args['input']);
            return $result['attendance'];
        case 'updateAttendance':
            $result = api_data('attendance/' . positive_id($args['id']), 'PATCH', $args['input']);
            return $result['attendance'];
        case 'createIncidence':
            $result = api_data('incidences', 'POST', $args['input']);
            return $result['incidence'];
        case 'updateIncidence':
            $result = api_data('incidences/' . positive_id($args['id']), 'PATCH', $args['input']);
            return $result['incidence'];
        case 'createPayroll':
            return api_data('payrolls', 'POST', $args['input']);
        case 'payPayroll':
            return api_data('payrolls/' . positive_id($args['id']) . '/pay', 'POST');
        case 'cancelPayroll':
            return api_data('payrolls/' . positive_id($args['id']) . '/cancel', 'POST', ['reason' => $args['reason']]);
        default:
            throw new ApiException(400, 'La mutación GraphQL no existe.');
    }
}

function graphql_error(Error $error): array
{
    $formatted = FormattedError::createFromException($error);
    $previous = $error->getPrevious();
    if ($previous !== null) {
        [$status, $body] = api_error($previous);
        $formatted['message'] = $body['message'];
        $formatted['extensions'] = ['status' => $status];
    } else {
        $formatted['extensions'] = ['status' => 400];
    }
    return $formatted;
}

function graphql_execute(array $request): array
{
    if (!isset($request['query']) || !is_string($request['query']) || trim($request['query']) === '') {
        throw new ApiException(400, 'Envía una consulta GraphQL en el campo query.');
    }
    $variables = $request['variables'] ?? null;
    if ($variables !== null && (!is_array($variables) || ($variables !== [] && array_is_list($variables)))) {
        throw new ApiException(400, 'variables debe ser un objeto JSON.');
    }
    if (isset($request['operationName']) && !is_string($request['operationName'])) {
        throw new ApiException(400, 'operationName debe ser una cadena.');
    }
    $result = GraphQL::executeQuery(
        graphql_schema(), $request['query'], null, null, $variables, $request['operationName'] ?? null,
        'graphql_resolve', [...GraphQL::getStandardValidationRules(), new QueryDepth(12), new QueryComplexity(500)]
    );
    $result->setErrorFormatter('graphql_error');
    return $result->toArray();
}
