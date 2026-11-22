<?php

/*
|--------------------------------------------------------------------------
| LOGIN - MATERIAS PRIMAS IA
|--------------------------------------------------------------------------
*/

// Configuración de sesión

$https = (
    !empty($_SERVER["HTTPS"]) &&
    $_SERVER["HTTPS"] !== "off"
);

session_set_cookie_params([
    "lifetime" => 0,
    "path" => "/",
    "secure" => $https,
    "httponly" => true,
    "samesite" => "Lax"
]);

session_start();

// Conexión a MySQL

require_once __DIR__ . "/conexion.php";

/*
|--------------------------------------------------------------------------
| MOSTRAR MENSAJES
|--------------------------------------------------------------------------
*/

function mostrarMensaje($mensaje, $codigo = 401) {

    http_response_code($codigo);

    echo '
    <!DOCTYPE html>
    <html lang="es">

    <head>

        <meta charset="UTF-8">

        <meta name="viewport"
              content="width=device-width, initial-scale=1.0">

        <title>Acceso | Materias Primas IA</title>

        <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4.1.11"></script>

        <link rel="stylesheet"
              href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    </head>

    <body class="min-h-screen bg-stone-950 flex items-center justify-center p-5">

        <div class="bg-white rounded-3xl shadow-2xl p-8 max-w-md w-full text-center">

            <div class="text-amber-700 text-5xl mb-5">

                <i class="fa-solid fa-circle-exclamation"></i>

            </div>

            <h1 class="text-2xl font-bold text-stone-900 mb-3">

                No fue posible iniciar sesión

            </h1>

            <p class="text-stone-600 mb-7">

                ' . htmlspecialchars($mensaje, ENT_QUOTES, "UTF-8") . '

            </p>

            <a href="acceso.html"
               class="block w-full bg-amber-700 hover:bg-amber-800
                      text-white font-semibold py-3 rounded-xl transition">

                <i class="fa-solid fa-arrow-left mr-2"></i>

                Volver al acceso

            </a>

        </div>

    </body>

    </html>';

    exit();

}

/*
|--------------------------------------------------------------------------
| VALIDAR MÉTODO HTTP
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] !== "POST") {

    http_response_code(405);

    header("Allow: POST");

    exit("Método no permitido.");

}

/*
|--------------------------------------------------------------------------
| RECIBIR DATOS DEL FORMULARIO
|--------------------------------------------------------------------------
*/

$correo = strtolower(trim($_POST["correo"] ?? ""));

$password = $_POST["password"] ?? "";

/*
|--------------------------------------------------------------------------
| VALIDAR CAMPOS
|--------------------------------------------------------------------------
*/

if ($correo === "" || $password === "") {

    mostrarMensaje(
        "Debes ingresar tu correo electrónico y contraseña."
    );

}

if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {

    mostrarMensaje(
        "El correo electrónico o la contraseña son incorrectos."
    );

}

/*
|--------------------------------------------------------------------------
| CONSULTAR USUARIO
|--------------------------------------------------------------------------
*/

try {

    $sql = "
        SELECT
            id,
            nombre,
            correo,
            password,
            rol,
            estado

        FROM usuarios

        WHERE correo = :correo

        LIMIT 1
    ";

    $consulta = $pdo->prepare($sql);

    $consulta->execute([
        ":correo" => $correo
    ]);

    $usuario = $consulta->fetch();

    /*
    |--------------------------------------------------------------------------
    | VERIFICAR CREDENCIALES
    |--------------------------------------------------------------------------
    */

    if (
        !$usuario ||
        !password_verify($password, $usuario["password"])
    ) {

        mostrarMensaje(
            "El correo electrónico o la contraseña son incorrectos."
        );

    }

    /*
    |--------------------------------------------------------------------------
    | VERIFICAR ESTADO DE LA CUENTA
    |--------------------------------------------------------------------------
    */

    if ((int)$usuario["estado"] !== 1) {

        mostrarMensaje(
            "Esta cuenta se encuentra desactivada. Contacta al administrador."
        );

    }

    /*
    |--------------------------------------------------------------------------
    | VALIDAR ROL (4 NIVELES: 1 Cliente, 2 Operador, 3 Supervisor, 4 Admin)
    |--------------------------------------------------------------------------
    */

    $rolesPermitidos = [
        "cliente",
        "operador",
        "supervisor",
        "admin"
    ];

    $rol = strtolower($usuario["rol"]);

    if (!in_array($rol, $rolesPermitidos, true)) {

        mostrarMensaje(
            "La cuenta tiene un rol no reconocido. Contacta al administrador."
        );

    }

    /*
    |--------------------------------------------------------------------------
    | CREAR SESIÓN
    |--------------------------------------------------------------------------
    */

    session_regenerate_id(true);

    $_SESSION["usuario_id"] = (int)$usuario["id"];

    $_SESSION["nombre"] = $usuario["nombre"];

    $_SESSION["correo"] = $usuario["correo"];

    $_SESSION["rol"] = $rol;

    $_SESSION["autenticado"] = true;

    /*
    |--------------------------------------------------------------------------
    | REDIRECCIONAR SEGÚN EL ROL
    |--------------------------------------------------------------------------
    */

    $destinos = [
        "cliente"    => "tienda.php",
        "operador"   => "panel.php",
        "supervisor" => "panel.php",
        "admin"      => "panel.php"
    ];

    header("Location: " . $destinos[$rol]);

    exit();

} catch (PDOException $e) {

    error_log(
        "Error en inicio de sesión: " . $e->getMessage()
    );

    mostrarMensaje(
        "Ocurrió un problema al verificar tus datos. Inténtalo nuevamente.",
        500
    );

}

?>