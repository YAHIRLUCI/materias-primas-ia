
<?php

session_start();

require_once __DIR__ . "/conexion.php";

// Token de seguridad
if (empty($_SESSION["token_admin"])) {
    $_SESSION["token_admin"] = bin2hex(random_bytes(32));
}

$mensaje = "";
$tipo = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Validar token
    if (
        !isset($_POST["token"]) ||
        !hash_equals($_SESSION["token_admin"], $_POST["token"])
    ) {
        $mensaje = "Solicitud no válida.";
        $tipo = "error";

    } else {

        $nombre = trim($_POST["nombre"] ?? "");
        $correo = strtolower(trim($_POST["correo"] ?? ""));
        $password = $_POST["password"] ?? "";
        $confirmar = $_POST["confirmar_password"] ?? "";

        try {

            // Comprobar si ya existe un administrador
            $consulta = $pdo->prepare(
                "SELECT id FROM usuarios WHERE rol = 'admin' LIMIT 1"
            );

            $consulta->execute();

            if ($consulta->fetch()) {

                $mensaje = "Ya existe una cuenta administrativa. No se puede crear otra desde este archivo.";
                $tipo = "error";

            } elseif (
                $nombre === "" ||
                !filter_var($correo, FILTER_VALIDATE_EMAIL) ||
                strlen($password) < 8 ||
                $password !== $confirmar
            ) {

                $mensaje = "Verifica los datos. La contraseña debe tener al menos 8 caracteres y ambas deben coincidir.";
                $tipo = "error";

            } else {

                // Comprobar que el correo no esté registrado
                $consulta = $pdo->prepare(
                    "SELECT id FROM usuarios WHERE correo = :correo LIMIT 1"
                );

                $consulta->execute([
                    ":correo" => $correo
                ]);

                if ($consulta->fetch()) {

                    $mensaje = "Este correo ya está registrado.";
                    $tipo = "error";

                } else {

                    // Cifrar contraseña
                    $passwordHash = password_hash(
                        $password,
                        PASSWORD_DEFAULT
                    );

                    // Crear administrador
                    $insertar = $pdo->prepare(
                        "INSERT INTO usuarios
                        (nombre, correo, password, rol)
                        VALUES
                        (:nombre, :correo, :password, 'admin')"
                    );

                    $insertar->execute([
                        ":nombre" => $nombre,
                        ":correo" => $correo,
                        ":password" => $passwordHash
                    ]);

                    $mensaje = "La cuenta administrativa fue creada correctamente. Ya puedes iniciar sesión.";
                    $tipo = "exito";

                    // Renovar token
                    $_SESSION["token_admin"] = bin2hex(random_bytes(32));
                }
            }

        } catch (PDOException $e) {

            error_log("Error al crear administrador: " . $e->getMessage());

            $mensaje = "Ocurrió un error al crear la cuenta. Revisa la conexión y la estructura de la base de datos.";
            $tipo = "error";
        }
    }
}

?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crear administrador | Materias Primas IA</title>

    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4.1.11"></script>
</head>

<body class="min-h-screen bg-stone-100 flex items-center justify-center p-5">

    <main class="bg-white w-full max-w-md rounded-2xl shadow-xl p-8">

        <h1 class="text-2xl font-bold text-amber-950 mb-2">
            Crear administrador
        </h1>

        <p class="text-sm text-stone-500 mb-6">
            Configuración inicial de Materias Primas IA.
        </p>

        <?php if ($mensaje !== ""): ?>

            <div class="p-3 mb-5 rounded-lg text-sm
                <?= $tipo === "exito"
                    ? "bg-green-100 text-green-800"
                    : "bg-red-100 text-red-800" ?>">

                <?= htmlspecialchars($mensaje, ENT_QUOTES, "UTF-8") ?>

            </div>

        <?php endif; ?>

        <?php if ($tipo !== "exito"): ?>

            <form method="POST" autocomplete="off">

                <input
                    type="hidden"
                    name="token"
                    value="<?= htmlspecialchars($_SESSION["token_admin"]) ?>"
                >

                <label class="block text-sm font-semibold mb-2">
                    Nombre completo
                </label>

                <input
                    type="text"
                    name="nombre"
                    maxlength="150"
                    required
                    class="w-full border rounded-lg p-3 mb-4"
                    placeholder="Nombre del administrador"
                >

                <label class="block text-sm font-semibold mb-2">
                    Correo electrónico
                </label>

                <input
                    type="email"
                    name="correo"
                    maxlength="150"
                    required
                    class="w-full border rounded-lg p-3 mb-4"
                    placeholder="admin@correo.com"
                >

                <label class="block text-sm font-semibold mb-2">
                    Contraseña
                </label>

                <input
                    type="password"
                    name="password"
                    minlength="8"
                    required
                    class="w-full border rounded-lg p-3 mb-4"
                    placeholder="Mínimo 8 caracteres"
                >

                <label class="block text-sm font-semibold mb-2">
                    Confirmar contraseña
                </label>

                <input
                    type="password"
                    name="confirmar_password"
                    minlength="8"
                    required
                    class="w-full border rounded-lg p-3 mb-6"
                    placeholder="Repite la contraseña"
                >

                <button
                    type="submit"
                    class="w-full bg-amber-700 hover:bg-amber-800
                    text-white font-bold p-3 rounded-lg">

                    Crear cuenta administrativa

                </button>

            </form>

        <?php else: ?>

            <a
                href="acceso.html"
                class="block text-center bg-amber-700 hover:bg-amber-800
                text-white font-bold p-3 rounded-lg">

                Ir al inicio de sesión

            </a>

        <?php endif; ?>

    </main>

</body>
</html>
