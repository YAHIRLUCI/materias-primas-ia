
<?php

session_start();

require_once __DIR__ . "/conexion.php";

/*
|--------------------------------------------------------------------------
| RESPUESTA VISUAL
|--------------------------------------------------------------------------
*/

function responder($mensaje, $tipo = "error") {

    $color = ($tipo === "success") ? "#15803d" : "#b45309";

    $titulo = ($tipo === "success")
        ? "Registro exitoso"
        : "No fue posible completar el registro";

    echo '
    <!DOCTYPE html>
    <html lang="es">

    <head>

        <meta charset="UTF-8">

        <meta name="viewport"
              content="width=device-width, initial-scale=1.0">

        <title>' . htmlspecialchars($titulo) . ' | Materias Primas IA</title>

        <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4.1.11"></script>

        <link rel="stylesheet"
              href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    </head>

    <body class="min-h-screen bg-stone-950 flex items-center justify-center p-5">

        <div class="bg-white rounded-3xl shadow-2xl p-8 max-w-md w-full text-center">

            <div class="text-5xl mb-5"
                 style="color:' . $color . '">

                <i class="fa-solid ' .
                (($tipo === "success") ? "fa-circle-check" : "fa-circle-exclamation") .
                '"></i>

            </div>

            <h1 class="text-2xl font-bold text-stone-900 mb-3">

                ' . htmlspecialchars($titulo) . '

            </h1>

            <p class="text-stone-600 mb-7">

                ' . htmlspecialchars($mensaje) . '

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
| VALIDAR MÉTODO DE ENVÍO
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] !== "POST") {

    responder("La solicitud no está permitida.");

}

/*
|--------------------------------------------------------------------------
| RECIBIR DATOS
|--------------------------------------------------------------------------
*/

$nombre = trim($_POST["nombre"] ?? "");

$correo = trim($_POST["correo"] ?? "");

$password = $_POST["password"] ?? "";

$confirmar_password = $_POST["confirmar_password"] ?? "";

/*
|--------------------------------------------------------------------------
| VALIDAR CAMPOS
|--------------------------------------------------------------------------
*/

if (
    empty($nombre) ||
    empty($correo) ||
    empty($password) ||
    empty($confirmar_password)
) {

    responder("Debes completar todos los campos del formulario.");

}

/*
|--------------------------------------------------------------------------
| VALIDAR NOMBRE
|--------------------------------------------------------------------------
*/

if (mb_strlen($nombre) > 150) {

    responder("El nombre es demasiado largo.");

}

/*
|--------------------------------------------------------------------------
| VALIDAR CORREO
|--------------------------------------------------------------------------
*/

if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {

    responder("Ingresa una dirección de correo electrónico válida.");

}

if (strlen($correo) > 150) {

    responder("El correo electrónico es demasiado largo.");

}

/*
|--------------------------------------------------------------------------
| VALIDAR CONTRASEÑA
|--------------------------------------------------------------------------
*/

if (strlen($password) < 8) {

    responder("La contraseña debe tener al menos 8 caracteres.");

}

if ($password !== $confirmar_password) {

    responder("Las contraseñas no coinciden.");

}

/*
|--------------------------------------------------------------------------
| VERIFICAR SI EL CORREO YA EXISTE
|--------------------------------------------------------------------------
*/

try {

    $consulta = $pdo->prepare(
        "SELECT id FROM usuarios WHERE correo = :correo LIMIT 1"
    );

    $consulta->execute([
        ":correo" => $correo
    ]);

    if ($consulta->fetch()) {

        responder("Este correo electrónico ya está registrado.");

    }

    /*
    |--------------------------------------------------------------------------
    | CIFRAR CONTRASEÑA
    |--------------------------------------------------------------------------
    */

    $password_hash = password_hash(
        $password,
        PASSWORD_DEFAULT
    );

    /*
    |--------------------------------------------------------------------------
    | ASIGNAR ROL DE CLIENTE
    |--------------------------------------------------------------------------
    */

    // El rol no se recibe desde el formulario.
    // Se establece directamente desde el servidor.

    $rol = "cliente";

    /*
    |--------------------------------------------------------------------------
    | INSERTAR USUARIO
    |--------------------------------------------------------------------------
    */

    $sql = "
        INSERT INTO usuarios
        (
            nombre,
            correo,
            password,
            rol
        )
        VALUES
        (
            :nombre,
            :correo,
            :password,
            :rol
        )
    ";

    $insertar = $pdo->prepare($sql);

    $insertar->execute([

        ":nombre" => $nombre,

        ":correo" => $correo,

        ":password" => $password_hash,

        ":rol" => $rol

    ]);

    /*
    |--------------------------------------------------------------------------
    | REGISTRO COMPLETADO
    |--------------------------------------------------------------------------
    */

    responder(
        "Tu cuenta de Cliente fue creada correctamente. Ya puedes iniciar sesión.",
        "success"
    );

} catch (PDOException $e) {

    error_log("Error al registrar usuario: " . $e->getMessage());

    responder(
        "Ocurrió un problema al guardar tu cuenta. Inténtalo nuevamente."
    );

}

?>
