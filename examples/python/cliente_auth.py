#!/usr/bin/env python3
"""Demuestra autenticación PHP desde Python 3, sin paquetes externos.

Realiza login, consulta la identidad, revoca la sesión y confirma la revocación.
Salida: 0 = autenticación comprobada, 1 = acceso rechazado, 2 = otro error.
"""

from __future__ import annotations

import argparse
import getpass
from http.client import HTTPException
import json
import os
import sys
from urllib.error import HTTPError, URLError
from urllib.parse import parse_qsl, urlencode, urlsplit, urlunsplit
from urllib.request import Request, urlopen


class ProtocolError(Exception):
    """La API no respondió conforme al contrato esperado."""


def endpoint(base_url: str, route: str) -> str:
    try:
        parsed = urlsplit(base_url)
        parsed.port  # Valida también un puerto escrito manualmente en --url.
    except ValueError as error:
        raise ProtocolError("La URL de la API no es válida.") from error
    if parsed.scheme not in {"http", "https"} or not parsed.netloc:
        raise ProtocolError("La URL debe comenzar con http:// o https://.")
    if parsed.username is not None or parsed.password is not None:
        raise ProtocolError("No incluyas credenciales dentro de la URL.")
    query = [(key, value) for key, value in parse_qsl(parsed.query) if key != "route"]
    query.append(("route", route))
    return urlunsplit((parsed.scheme, parsed.netloc, parsed.path, urlencode(query), ""))


def request_api(base_url: str, route: str, method: str = "GET", *,
                body: dict | None = None, token: str | None = None) -> tuple[int, dict]:
    headers = {"Accept": "application/json"}
    data = None
    if body is not None:
        headers["Content-Type"] = "application/json"
        data = json.dumps(body).encode("utf-8")
    if token is not None:
        headers["Authorization"] = "Bearer " + token
    try:
        request = Request(endpoint(base_url, route), data=data, headers=headers, method=method)
        with urlopen(request, timeout=10) as response:
            status, payload = response.status, response.read()
    except HTTPError as error:
        # 401 también contiene JSON: no es un error de conectividad.
        with error:
            status, payload = error.code, error.read()
    except (URLError, TimeoutError, OSError, HTTPException, ValueError) as error:
        raise ProtocolError("No se pudo conectar con la API. Revisa Apache/PHP, MySQL y --url.") from error
    try:
        decoded = json.loads(payload.decode("utf-8"))
    except (ValueError, UnicodeError) as error:
        raise ProtocolError("La API no devolvió JSON válido. Revisa la URL y la configuración del servidor.") from error
    if not isinstance(decoded, dict):
        raise ProtocolError("La respuesta de la API debe ser un objeto JSON.")
    return status, decoded


def print_json(value: dict, *, error: bool = False) -> None:
    print(json.dumps(value, ensure_ascii=False, indent=2), file=sys.stderr if error else sys.stdout)


def run(base_url: str, email: str, password: str) -> int:
    status, login = request_api(base_url, "auth/login", "POST", body={"email": email, "password": password})
    if status == 401 and login.get("authenticated") is False:
        print_json({"authenticated": False, "message": "Correo o contraseña incorrectos."})
        return 1
    if status == 422:
        raise ProtocolError("Datos de acceso inválidos. Comprueba el correo y la contraseña.")
    if status == 429:
        raise ProtocolError("Demasiados intentos. Espera antes de volver a intentar.")
    token = login.get("token")
    if status != 200 or login.get("authenticated") is not True or not isinstance(token, str) or not token:
        raise ProtocolError("El inicio de sesión no devolvió el contrato esperado (HTTP " + str(status) + ").")

    revoked = False
    try:
        status, identity = request_api(base_url, "auth/me", token=token)
        if status != 200 or identity.get("authenticated") is not True or not isinstance(identity.get("user"), dict):
            raise ProtocolError("La API no pudo validar la identidad de la sesión.")

        status, logout = request_api(base_url, "auth/logout", "POST", body={}, token=token)
        if status != 200 or logout.get("authenticated") is not False:
            raise ProtocolError("No se pudo confirmar el cierre de sesión.")
        revoked = True

        status, after_logout = request_api(base_url, "auth/me", token=token)
        if status != 401 or after_logout.get("authenticated") is not False:
            raise ProtocolError("La API aceptó una sesión que ya había sido cerrada.")

        print_json({
            "authenticated": True,
            "user": identity["user"],
            "expires_at": login.get("expires_at"),
            "session_revoked": True,
            "revoked_token_authenticated": False,
        })
        return 0
    finally:
        # Si falla una comprobación intermedia, también intentamos revocar la sesión.
        if not revoked:
            try:
                request_api(base_url, "auth/logout", "POST", body={}, token=token)
            except ProtocolError:
                pass


def main() -> int:
    # JSON UTF-8 también al redirigir la salida en Windows.
    sys.stdout.reconfigure(encoding="utf-8")
    sys.stderr.reconfigure(encoding="utf-8")
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--url", default="http://127.0.0.1:8088/api/index.php", help="URL base de la API PHP")
    parser.add_argument("--email", help="Correo de usuario; si se omite, se solicita de forma interactiva")
    args = parser.parse_args()
    try:
        email = (args.email if args.email is not None else input("Correo: ")).strip()
        password = os.environ.get("NOMINA_PASSWORD")
        if password is None:
            password = getpass.getpass("Contraseña: ")
        if not email or not password:
            raise ProtocolError("El correo y la contraseña son obligatorios.")
        return run(args.url, email, password)
    except (EOFError, KeyboardInterrupt):
        print_json({"error": "Operación cancelada."}, error=True)
        return 2
    except ProtocolError as error:
        print_json({"error": str(error)}, error=True)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
