
<?php

/*
|--------------------------------------------------------------------------
| CONEXIÓN A LA BASE DE DATOS
| Proyecto: Materias Primas IA
|--------------------------------------------------------------------------
*/

// Configuración de MySQL

$host = "localhost";

$base_datos = "materias_primas_ia";

$usuario = "root";

$password = "";

// Configuración de PDO

try {

    $pdo = new PDO(
        "mysql:host=$host;dbname=$base_datos;charset=utf8mb4",
        $usuario,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,

            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );

} catch (PDOException $e) {

    error_log("Error de conexión: " . $e->getMessage());

    http_response_code(500);

    die("No fue posible conectar con la base de datos.");

}

?>
