# WebScenarioStatusBoard — Module Configuration

This document explains the minimal configuration required to install and use the WebScenarioStatusBoard module in Zabbix. It covers how to set the API URL and the `apiToken` in the configuration file.

**Requirements**
 - Zabbix frontend where custom modules can be deployed.
 - An API token (or auth token) with read permissions for hosts, web scenarios, items and history.

**Configuration file**
 - Location in the repository: `WebScenarioStatusBoard/config.json`

Main structure and fields:

```json
{
  "apiUrl": "http://<zabbix-server>/api_jsonrpc.php",
  "apiToken": "<API_TOKEN_OR_AUTH>",
  "groupIdsMacro": "{$GROUPIDS}",
  "refreshIntervalSeconds": 60
}
```

 - `apiUrl`: Full URL to the Zabbix JSON-RPC endpoint. Usually ends with `/api_jsonrpc.php`. Use `https://` when possible.
 - `apiToken`: Authentication token the module uses to query the API. Prefer using an API token created in Zabbix (recommended). As an alternative, you can store the `user.login` auth token here.
- `groupIdsMacro`: Name of the global macro used to resolve host group IDs. Default is `{$GROUPIDS}`. You can change it to another macro (for example `{$MY_GROUPS}`) and the module will use that macro instead.
 - `refreshIntervalSeconds`: Interval in seconds to refresh the data in the module UI.

**How to obtain/create a token in Zabbix**
 - Zabbix 5.4+ (or versions with API token UI): go to Administration → Users → API tokens and create a token with read scope for the user you want to use.
 - Alternative: if your installation does not use API tokens, perform a `user.login` to obtain an auth token and place that value in `apiToken`.

**Required permissions**
The token/user must have read access to:
 - Host groups / Hosts
 - Web scenarios (httptests)
 - Items related to scenario steps (`webitems`)
 - History access (`history.get`)

If the token lacks `history.get` permissions, charts may be empty or show limited data.

**How group filtering works**
- The module reads the global macro configured in `groupIdsMacro` (default: `{$GROUPIDS}`).
- The macro value must contain comma-separated group IDs, for example: `2,5,18`.
- If the configured macro does not exist or is empty, the module falls back to querying all host groups.

**Module installation (summary)**
 - Copy the module folder to the Zabbix frontend modules directory (e.g. `/usr/share/zabbix/modules/` or the equivalent for your installation).
 - Put `config.json` in the root of the module (next to `Module.php`).
 - Ensure the web server/PHP process can read the file.

Typical Linux commands (adjust user/group for your distro):

```bash
# copy module (example)
sudo cp -r WebScenarioStatusBoard /usr/share/zabbix/modules/
# set ownership
sudo chown -R www-data:www-data /usr/share/zabbix/modules/WebScenarioStatusBoard
# tighten config permissions
sudo chmod 640 /usr/share/zabbix/modules/WebScenarioStatusBoard/config.json
# restart webserver / php-fpm
sudo systemctl restart apache2
# or
sudo systemctl restart php-fpm
```

Note: on Windows, place the module folder in the web frontend directory and ensure the IIS user has read permissions.

**Verify the installation**
 - Open the Zabbix web UI and navigate to the module (depending on how you integrated it into the menu).
 - Check the browser console and PHP/web server logs if the module does not appear or there are connection errors.

**Common issues and troubleshooting**
 - Connection refused / host unreachable: verify `apiUrl` is reachable from the web server (for example: `curl http://<zabbix-server>/api_jsonrpc.php`).
 - 401 / Unauthorized: token invalid or lacks permissions. Verify the token and required read permissions.
 - Empty charts: check history retention and permissions; use the 1h timeframe to validate recent data.
 - History request timeouts: increase PHP execution limits or tune the module's batch limits if needed.

**Example configuration (included)**
 - A sample `config.json` is provided in the module root: `WebScenarioStatusBoard/config.json`. Update `apiUrl` and `apiToken` for your environment.

**Recommendations**
 - Create a dedicated user for the module with the minimum permissions needed and generate an API token for that user.
 - Use `https://` in `apiUrl` when running in production and limit the token's scope.

If you want, I can add platform-specific installation steps (Ubuntu/CentOS/RHEL/IIS) or generate a small validation script to check `apiUrl` and `apiToken` connectivity. Tell me which platform you use and I will add it.
# WebScenarioStatusBoard — Configuración del módulo

Este documento explica la configuración mínima necesaria para instalar y usar el módulo WebScenarioStatusBoard en Zabbix: cómo indicar la URL de la API y el token (apiToken) en el archivo de configuración.

**Requisitos**
- Zabbix frontend donde puedas desplegar módulos personalizados.
- Un token/API key con permisos de lectura sobre hosts, web scenarios, items y history.

**Archivo de configuración**
- Ubicación del archivo en el repositorio: [WebScenarioStatusBoard/config.json](WebScenarioStatusBoard/config.json)

Estructura y campos principales:

```json
{
  "apiUrl": "http://<zabbix-server>/api_jsonrpc.php",
  "apiToken": "<TOKEN_DE_API_O_AUTH>",
  "refreshIntervalSeconds": 60
}
```

- apiUrl: URL completa al endpoint JSON-RPC de Zabbix. Normalmente termina en `/api_jsonrpc.php` y puede ser http o https según tu instalación.
- apiToken: token de autenticación que use el módulo para consultar la API. Puede ser un API token creado en Zabbix (recomendado) o un token generado por el método de autenticación que use tu instalación.
- refreshIntervalSeconds: intervalo (en segundos) para refrescar la información automáticamente en la interfaz del módulo.

**Cómo obtener/crear el token en Zabbix**
- Zabbix 5.4+ (o versiones con la UI para tokens): Administración → Usuarios → API tokens (o Administración → API tokens). Crear un token con alcance de lectura para el usuario que deseas usar.
- Alternativa: usar `user.login` para obtener un auth token si tu instalación no utiliza API tokens; en ese caso, guarda el token resultante en `apiToken`.

**Permisos necesarios**
- El token/usuario debe tener permisos de lectura sobre:
  - Host groups / Hosts
  - Web scenarios (httptests)
  - Items relacionados con los pasos (webitems)
  - Historial (history.get)

Si no hay permisos de `history.get`, los gráficos pueden aparecer vacíos o con datos limitados.

**Instalación del módulo (resumen)**
- Copiar la carpeta del módulo al directorio de frontend de Zabbix (p. ej. `/usr/share/zabbix/modules/` o la ubicación equivalente de tu instalación).
- Colocar `config.json` en la raíz del módulo (donde está `Module.php`).
- Asegurar permisos del fichero para que el proceso web (apache/nginx/php-fpm) pueda leerlo.

Comandos típicos en Linux (ajusta usuario/grupo según tu distro):

```bash
# copiar módulo (ejemplo)
sudo cp -r WebScenarioStatusBoard /usr/share/zabbix/modules/
# ajustar permisos
sudo chown -R www-data:www-data /usr/share/zabbix/modules/WebScenarioStatusBoard
sudo chmod 640 /usr/share/zabbix/modules/WebScenarioStatusBoard/config.json
# reiniciar servidor web / php-fpm
sudo systemctl restart apache2
# o
sudo systemctl restart php-fpm
```

Nota: en Windows coloca la carpeta del módulo en el directorio del frontend web y asegúrate de que el IIS/usuario tenga permisos de lectura.

**Verificar instalación**
- Abrir la interfaz web de Zabbix y navegar al módulo (dependiendo de cómo hayas integrado el módulo en el menú).
- Revisar la consola del navegador y los logs de PHP/servidor web si no aparece o si hay errores de conexión.

**Problemas comunes y soluciones**
- Conexión rechazada / host unreachable: verificar que `apiUrl` es accesible desde el servidor web (puedes probar `curl http://<zabbix-server>/api_jsonrpc.php`).
- 401 / Unauthorized: token inválido o sin permisos. Verifica el token y los permisos (lectura para hosts/webitems/history).
- Gráficos vacíos: comprobar políticas de retención de history en Zabbix y permisos; usar el timeframe de 1h para validar datos recientes.
- Timeout al solicitar history: aumentar límites o revisar la configuración de tiempo de ejecución de PHP si el servidor limita las peticiones largas.

**Ejemplo mínimo (incluido)**
- Hay un ejemplo de configuración en [WebScenarioStatusBoard/config.json](WebScenarioStatusBoard/config.json). Adapta `apiUrl` y `apiToken` a tu entorno.

**Siguiente pasos / recomendaciones**
- Crear un usuario dedicado para el módulo con permisos mínimos necesarios y generar un token para ese usuario.
- Si el módulo se usa en producción, configurar HTTPS en `apiUrl` y restringir el alcance del token.

Si quieres, puedo añadir instrucciones específicas para tu sistema (RHEL/CentOS/Ubuntu/IIS) o generar un script de despliegue. Comentame qué prefieres.
