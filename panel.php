
<?php

session_start();
require_once __DIR__ . "/conexion.php";

/* =====================================================
   1. VERIFICAR SESIÓN
===================================================== */

if (
    !isset($_SESSION["autenticado"]) ||
    $_SESSION["autenticado"] !== true ||
    !isset($_SESSION["rol"])
) {
    header("Location: acceso.html");
    exit();
}

$rol = strtolower($_SESSION["rol"]);

if ($rol === "cliente") {
    header("Location: tienda.php");
    exit();
}

/* =====================================================
   2. ROLES Y PERMISOS
===================================================== */

$permisosDisponibles = [
    "pedidos", "capturar", "editar", "surtir",
    "inventario", "productos", "usuarios",
    "reportes", "config", "borrar",
    "seguridad", "tienda"
];

$roles = [
    "admin" => [
        "nombre" => "Admin Jefe",
        "nivel" => 1,
        "permisos" => $permisosDisponibles
    ],

    "supervisor" => [
        "nombre" => "Supervisor",
        "nivel" => 2,
        "permisos" => array_values(
            array_diff($permisosDisponibles, ["seguridad", "borrar"])
        )
    ],

    "operador" => [
        "nombre" => "Operador de Almacén",
        "nivel" => 3,
        "permisos" => ["pedidos", "surtir", "inventario"]
    ],

    "capturista" => [
        "nombre" => "Capturista",
        "nivel" => 3,
        "permisos" => ["pedidos", "capturar", "editar", "tienda"]
    ]
];

if (!isset($roles[$rol])) {
    session_unset();
    session_destroy();
    header("Location: acceso.html");
    exit();
}

$usuarioActual = $_SESSION["nombre"] ?? "Usuario";
$correoActual = $_SESSION["correo"] ?? "";
$rolActual = $roles[$rol]["nombre"];
$permisos = $roles[$rol]["permisos"];

/* =====================================================
   3. FUNCIONES AUXILIARES
===================================================== */

function escapar($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, "UTF-8");
}

function tienePermiso($permiso) {
    global $permisos;
    return in_array($permiso, $permisos, true);
}

function redirigir($modulo, $resultado) {
    header(
        "Location: panel.php?modulo=" .
        urlencode($modulo) .
        "&resultado=" .
        urlencode($resultado) .
        "#" . $modulo
    );
    exit();
}

/*
 * Valida las presentaciones recibidas desde los formularios.
 * El stock pertenece a cada presentación, no al producto.
 */
function obtenerPresentacionesPOST($esEdicion = false) {

    $nombres = $_POST["nombre_presentacion"] ?? [];
    $valores = $_POST["valor_medida"] ?? [];
    $unidades = $_POST["unidad"] ?? [];
    $precios = $_POST["precio"] ?? [];
    $stocks = $_POST["stock"] ?? [];
    $ids = $_POST["id_presentacion"] ?? [];

    if (
        !is_array($nombres) ||
        !is_array($valores) ||
        !is_array($unidades) ||
        !is_array($precios) ||
        !is_array($stocks)
    ) {
        throw new Exception("Los datos de las presentaciones no son válidos.");
    }

    $total = count($nombres);

    if ($total < 1 || $total > 30) {
        throw new Exception("Debes registrar entre 1 y 30 presentaciones.");
    }

    $resultado = [];

    for ($i = 0; $i < $total; $i++) {

        $nombre = trim($nombres[$i] ?? "");
        $valor = $valores[$i] ?? "";
        $unidad = trim($unidades[$i] ?? "");
        $precio = $precios[$i] ?? "";
        $stock = $stocks[$i] ?? "";

        if (
            $nombre === "" ||
            $unidad === "" ||
            !is_numeric($valor) ||
            !is_numeric($precio) ||
            !is_numeric($stock)
        ) {
            throw new Exception("Completa correctamente todas las presentaciones.");
        }

        $valor = (float)$valor;
        $precio = (float)$precio;
        $stock = (float)$stock;

        if (
            $valor <= 0 ||
            $precio < 0 ||
            $stock < 0 ||
            !is_finite($valor) ||
            !is_finite($precio) ||
            !is_finite($stock)
        ) {
            throw new Exception("Revisa las medidas, precios y existencias.");
        }

        if (
            mb_strlen($nombre) > 50 ||
            mb_strlen($unidad) > 20
        ) {
            throw new Exception("El nombre o la unidad de una presentación es demasiado largo.");
        }

        $id = 0;

        if ($esEdicion) {
            $id = isset($ids[$i]) ? (int)$ids[$i] : 0;
        }

        $resultado[] = [
            "id" => $id,
            "nombre" => $nombre,
            "valor" => $valor,
            "unidad" => $unidad,
            "precio" => $precio,
            "stock" => $stock
        ];
    }

    return $resultado;
}

/* =====================================================
   4. CERRAR SESIÓN
===================================================== */

if (isset($_GET["salir"])) {

    $_SESSION = [];

    if (ini_get("session.use_cookies")) {

        $parametros = session_get_cookie_params();

        setcookie(
            session_name(),
            "",
            time() - 42000,
            $parametros["path"],
            $parametros["domain"],
            $parametros["secure"],
            $parametros["httponly"]
        );
    }

    session_destroy();

    header("Location: acceso.html");
    exit();
}

/* =====================================================
   5. TOKEN CSRF
===================================================== */

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION["csrf_token"];

$mensaje = "";
$tipoMensaje = "";

/* =====================================================
   6. PROCESAR OPERACIONES POST
===================================================== */

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (
        !isset($_POST["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
    ) {
        http_response_code(403);
        die("Solicitud no válida.");
    }

    $accion = $_POST["accion"] ?? "";

    try {

        /* =============================================
           OPERACIONES DE USUARIOS
        ============================================= */

        if (in_array(
            $accion,
            ["crear_usuario", "actualizar_usuario", "cambiar_estado"],
            true
        )) {

            if ($rol !== "admin") {
                http_response_code(403);
                die("No tienes autorización para administrar usuarios.");
            }

            /* CREAR USUARIO */

            if ($accion === "crear_usuario") {

                $nombre = trim($_POST["nombre"] ?? "");
                $correo = strtolower(trim($_POST["correo"] ?? ""));
                $password = $_POST["password"] ?? "";
                $rolNuevo = strtolower(trim($_POST["rol"] ?? ""));

                $rolesPermitidos = [
                    "supervisor",
                    "operador",
                    "capturista"
                ];

                if (
                    $nombre === "" ||
                    $correo === "" ||
                    $password === "" ||
                    $rolNuevo === ""
                ) {
                    throw new Exception("Completa todos los campos.");
                }

                if (mb_strlen($nombre) > 150) {
                    throw new Exception("El nombre es demasiado largo.");
                }

                if (
                    strlen($correo) > 150 ||
                    !filter_var($correo, FILTER_VALIDATE_EMAIL)
                ) {
                    throw new Exception("Introduce un correo válido.");
                }

                if (strlen($password) < 8) {
                    throw new Exception("La contraseña debe tener al menos 8 caracteres.");
                }

                if (!in_array($rolNuevo, $rolesPermitidos, true)) {
                    throw new Exception("El rol seleccionado no está permitido.");
                }

                $consulta = $pdo->prepare(
                    "SELECT id FROM usuarios WHERE correo = :correo LIMIT 1"
                );

                $consulta->execute([":correo" => $correo]);

                if ($consulta->fetch()) {
                    throw new Exception("Este correo ya está registrado.");
                }

                $hash = password_hash($password, PASSWORD_DEFAULT);

                $insertar = $pdo->prepare(
                    "INSERT INTO usuarios
                    (nombre, correo, password, rol, estado)
                    VALUES (:nombre, :correo, :password, :rol, 1)"
                );

                $insertar->execute([
                    ":nombre" => $nombre,
                    ":correo" => $correo,
                    ":password" => $hash,
                    ":rol" => $rolNuevo
                ]);

                redirigir("usuarios", "creado");
            }

            /* EDITAR USUARIO */

            elseif ($accion === "actualizar_usuario") {

                $idEditar = filter_input(
                    INPUT_POST,
                    "id_usuario",
                    FILTER_VALIDATE_INT
                );

                $nombre = trim($_POST["nombre"] ?? "");
                $correo = strtolower(trim($_POST["correo"] ?? ""));
                $rolNuevo = strtolower(trim($_POST["rol"] ?? ""));

                $rolesPermitidos = [
                    "supervisor",
                    "operador",
                    "capturista",
                    "cliente"
                ];

                if (
                    !$idEditar ||
                    $nombre === "" ||
                    $correo === "" ||
                    $rolNuevo === ""
                ) {
                    throw new Exception("Verifica los datos del usuario.");
                }

                if (
                    $idEditar ===
                    (int)($_SESSION["usuario_id"] ?? 0)
                ) {
                    throw new Exception("No puedes modificar tu propia cuenta.");
                }

                if (mb_strlen($nombre) > 150) {
                    throw new Exception("El nombre es demasiado largo.");
                }

                if (
                    strlen($correo) > 150 ||
                    !filter_var($correo, FILTER_VALIDATE_EMAIL)
                ) {
                    throw new Exception("Introduce un correo válido.");
                }

                if (!in_array($rolNuevo, $rolesPermitidos, true)) {
                    throw new Exception("El rol seleccionado no está permitido.");
                }

                $buscar = $pdo->prepare(
                    "SELECT id, rol FROM usuarios WHERE id = :id LIMIT 1"
                );

                $buscar->execute([":id" => $idEditar]);
                $usuarioObjetivo = $buscar->fetch();

                if (
                    !$usuarioObjetivo ||
                    strtolower($usuarioObjetivo["rol"]) === "admin"
                ) {
                    throw new Exception("No está permitido modificar esa cuenta.");
                }

                $duplicado = $pdo->prepare(
                    "SELECT id FROM usuarios
                     WHERE correo = :correo AND id <> :id LIMIT 1"
                );

                $duplicado->execute([
                    ":correo" => $correo,
                    ":id" => $idEditar
                ]);

                if ($duplicado->fetch()) {
                    throw new Exception("El correo ya está siendo utilizado.");
                }

                $actualizar = $pdo->prepare(
                    "UPDATE usuarios
                     SET nombre = :nombre,
                         correo = :correo,
                         rol = :rol
                     WHERE id = :id AND rol <> 'admin'"
                );

                $actualizar->execute([
                    ":nombre" => $nombre,
                    ":correo" => $correo,
                    ":rol" => $rolNuevo,
                    ":id" => $idEditar
                ]);

                redirigir("usuarios", "actualizado");
            }

            /* ACTIVAR / DESACTIVAR USUARIO */

            elseif ($accion === "cambiar_estado") {

                $idEstado = filter_input(
                    INPUT_POST,
                    "id_usuario",
                    FILTER_VALIDATE_INT
                );

                $nuevoEstado = filter_input(
                    INPUT_POST,
                    "estado",
                    FILTER_VALIDATE_INT
                );

                if (
                    !$idEstado ||
                    !in_array($nuevoEstado, [0, 1], true)
                ) {
                    throw new Exception("El cambio de estado no es válido.");
                }

                if (
                    $idEstado ===
                    (int)($_SESSION["usuario_id"] ?? 0)
                ) {
                    throw new Exception("No puedes desactivar tu propia cuenta.");
                }

                $cambiar = $pdo->prepare(
                    "UPDATE usuarios
                     SET estado = :estado
                     WHERE id = :id AND rol <> 'admin'"
                );

                $cambiar->execute([
                    ":estado" => $nuevoEstado,
                    ":id" => $idEstado
                ]);

                if ($cambiar->rowCount() > 0) {
                    redirigir("usuarios", "estado");
                }

                throw new Exception("No se modificó el estado de la cuenta.");
            }
        }

        /* =============================================
           OPERACIONES DE PRODUCTOS
        ============================================= */

        elseif (in_array(
            $accion,
            [
                "crear_producto",
                "actualizar_producto",
                "cambiar_estado_producto"
            ],
            true
        )) {

            if (!tienePermiso("productos")) {
                http_response_code(403);
                die("No tienes autorización para administrar productos.");
            }

            /* CREAR PRODUCTO */

            if ($accion === "crear_producto") {

                $nombre = trim($_POST["nombre_producto"] ?? "");
                $descripcion = trim($_POST["descripcion"] ?? "");
                $imagen = trim($_POST["imagen_url"] ?? "");
                $idCategoria = $_POST["id_categoria"] ?? "";

                if ($nombre === "") {
                    throw new Exception("El nombre del producto es obligatorio.");
                }

                if (mb_strlen($nombre) > 150) {
                    throw new Exception("El nombre del producto es demasiado largo.");
                }

                if (mb_strlen($imagen) > 255) {
                    throw new Exception("La dirección de imagen es demasiado larga.");
                }

                if (
                    $imagen !== "" &&
                    !filter_var($imagen, FILTER_VALIDATE_URL)
                ) {
                    throw new Exception("La dirección de imagen no es válida.");
                }

                $idCategoria = ($idCategoria === "")
                    ? null
                    : (int)$idCategoria;

                if ($idCategoria !== null) {

                    $buscarCategoria = $pdo->prepare(
                        "SELECT id_categoria
                         FROM categorias
                         WHERE id_categoria = :id"
                    );

                    $buscarCategoria->execute([
                        ":id" => $idCategoria
                    ]);

                    if (!$buscarCategoria->fetch()) {
                        throw new Exception("La categoría seleccionada no existe.");
                    }
                }

                $presentaciones = obtenerPresentacionesPOST();

                $pdo->beginTransaction();

                $insertarProducto = $pdo->prepare(
                    "INSERT INTO productos
                    (id_categoria, nombre, descripcion, imagen_url, activo)
                    VALUES (:categoria, :nombre, :descripcion, :imagen, 1)"
                );

                $insertarProducto->execute([
                    ":categoria" => $idCategoria,
                    ":nombre" => $nombre,
                    ":descripcion" => $descripcion,
                    ":imagen" => $imagen !== "" ? $imagen : null
                ]);

                $idProducto = (int)$pdo->lastInsertId();

                $insertarPresentacion = $pdo->prepare(
                    "INSERT INTO presentaciones_gramaje
                    (
                        id_producto,
                        nombre_presentacion,
                        valor_medida,
                        unidad,
                        precio,
                        stock
                    )
                    VALUES (?, ?, ?, ?, ?, ?)"
                );

                foreach ($presentaciones as $p) {

                    $insertarPresentacion->execute([
                        $idProducto,
                        $p["nombre"],
                        $p["valor"],
                        $p["unidad"],
                        $p["precio"],
                        $p["stock"]
                    ]);
                }

                $pdo->commit();

                redirigir("productos", "producto_creado");
            }

            /* ACTUALIZAR PRODUCTO */

            elseif ($accion === "actualizar_producto") {

                $idProducto = filter_input(
                    INPUT_POST,
                    "id_producto",
                    FILTER_VALIDATE_INT
                );

                $nombre = trim($_POST["nombre_producto"] ?? "");
                $descripcion = trim($_POST["descripcion"] ?? "");
                $imagen = trim($_POST["imagen_url"] ?? "");
                $idCategoria = $_POST["id_categoria"] ?? "";

                if (!$idProducto || $nombre === "") {
                    throw new Exception("Verifica los datos del producto.");
                }

                if (mb_strlen($nombre) > 150) {
                    throw new Exception("El nombre del producto es demasiado largo.");
                }

                if (mb_strlen($imagen) > 255) {
                    throw new Exception("La dirección de imagen es demasiado larga.");
                }

                if (
                    $imagen !== "" &&
                    !filter_var($imagen, FILTER_VALIDATE_URL)
                ) {
                    throw new Exception("La dirección de imagen no es válida.");
                }

                $idCategoria = ($idCategoria === "")
                    ? null
                    : (int)$idCategoria;

                if ($idCategoria !== null) {

                    $buscarCategoria = $pdo->prepare(
                        "SELECT id_categoria
                         FROM categorias
                         WHERE id_categoria = :id"
                    );

                    $buscarCategoria->execute([
                        ":id" => $idCategoria
                    ]);

                    if (!$buscarCategoria->fetch()) {
                        throw new Exception("La categoría seleccionada no existe.");
                    }
                }

                $presentaciones = obtenerPresentacionesPOST(true);

                $pdo->beginTransaction();

                $buscarProducto = $pdo->prepare(
                    "SELECT id_producto
                     FROM productos
                     WHERE id_producto = :id
                     FOR UPDATE"
                );

                $buscarProducto->execute([
                    ":id" => $idProducto
                ]);

                if (!$buscarProducto->fetch()) {
                    throw new Exception("El producto no existe.");
                }

                $actualizarProducto = $pdo->prepare(
                    "UPDATE productos
                     SET id_categoria = :categoria,
                         nombre = :nombre,
                         descripcion = :descripcion,
                         imagen_url = :imagen
                     WHERE id_producto = :id"
                );

                $actualizarProducto->execute([
                    ":categoria" => $idCategoria,
                    ":nombre" => $nombre,
                    ":descripcion" => $descripcion,
                    ":imagen" => $imagen !== "" ? $imagen : null,
                    ":id" => $idProducto
                ]);

                $buscarPresentacion = $pdo->prepare(
                    "SELECT id_presentacion
                     FROM presentaciones_gramaje
                     WHERE id_presentacion = ?
                       AND id_producto = ?"
                );

                $actualizarPresentacion = $pdo->prepare(
                    "UPDATE presentaciones_gramaje
                     SET nombre_presentacion = ?,
                         valor_medida = ?,
                         unidad = ?,
                         precio = ?,
                         stock = ?
                     WHERE id_presentacion = ?
                       AND id_producto = ?"
                );

                $insertarPresentacion = $pdo->prepare(
                    "INSERT INTO presentaciones_gramaje
                    (
                        id_producto,
                        nombre_presentacion,
                        valor_medida,
                        unidad,
                        precio,
                        stock
                    )
                    VALUES (?, ?, ?, ?, ?, ?)"
                );

                foreach ($presentaciones as $p) {

                    if ($p["id"] > 0) {

                        $buscarPresentacion->execute([
                            $p["id"],
                            $idProducto
                        ]);

                        if (!$buscarPresentacion->fetch()) {
                            throw new Exception(
                                "Una presentación no pertenece a este producto."
                            );
                        }

                        $actualizarPresentacion->execute([
                            $p["nombre"],
                            $p["valor"],
                            $p["unidad"],
                            $p["precio"],
                            $p["stock"],
                            $p["id"],
                            $idProducto
                        ]);

                    } else {

                        $insertarPresentacion->execute([
                            $idProducto,
                            $p["nombre"],
                            $p["valor"],
                            $p["unidad"],
                            $p["precio"],
                            $p["stock"]
                        ]);
                    }
                }

                $pdo->commit();

                redirigir("productos", "producto_actualizado");
            }

            /* ACTIVAR / DESACTIVAR PRODUCTO */

            elseif ($accion === "cambiar_estado_producto") {

                $idProducto = filter_input(
                    INPUT_POST,
                    "id_producto",
                    FILTER_VALIDATE_INT
                );

                $nuevoEstado = filter_input(
                    INPUT_POST,
                    "estado",
                    FILTER_VALIDATE_INT
                );

                if (
                    !$idProducto ||
                    !in_array($nuevoEstado, [0, 1], true)
                ) {
                    throw new Exception("La solicitud no es válida.");
                }

                $cambiar = $pdo->prepare(
                    "UPDATE productos
                     SET activo = :estado
                     WHERE id_producto = :id"
                );

                $cambiar->execute([
                    ":estado" => $nuevoEstado,
                    ":id" => $idProducto
                ]);

                if ($cambiar->rowCount() > 0) {
                    redirigir("productos", "producto_estado");
                }

                throw new Exception("No se modificó el estado del producto.");
            }
        }

        else {
            http_response_code(400);
            die("Acción no válida.");
        }

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log("Error en panel.php: " . $e->getMessage());

        $mensaje = $e instanceof PDOException
            ? "No fue posible completar la operación. Revisa los datos y la estructura de la base de datos."
            : $e->getMessage();

        $tipoMensaje = "error";
    }
}

/* =====================================================
   7. MENSAJES DE RESULTADO
===================================================== */

$resultados = [
    "creado" => "El usuario interno fue creado correctamente.",
    "actualizado" => "Los datos del usuario se actualizaron correctamente.",
    "estado" => "El estado de la cuenta se actualizó correctamente.",

    "producto_creado" => "El producto y sus presentaciones se registraron correctamente.",
    "producto_actualizado" => "El producto y sus presentaciones se actualizaron correctamente.",
    "producto_estado" => "El estado del producto se actualizó correctamente."
];

$resultado = $_GET["resultado"] ?? "";

if (isset($resultados[$resultado])) {
    $mensaje = $resultados[$resultado];
    $tipoMensaje = "exito";
}

/* =====================================================
   8. ESTADÍSTICAS DE USUARIOS
===================================================== */

$totalUsuarios = 0;
$usuariosPorRol = [];

try {

    $consultaTotal = $pdo->query(
        "SELECT COUNT(*) FROM usuarios"
    );

    $totalUsuarios = (int)$consultaTotal->fetchColumn();

    $consultaRoles = $pdo->query(
        "SELECT rol, COUNT(*) AS total
         FROM usuarios
         GROUP BY rol"
    );

    foreach ($consultaRoles->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $usuariosPorRol[$fila["rol"]] = (int)$fila["total"];
    }

} catch (PDOException $e) {
    error_log("Error al consultar estadísticas: " . $e->getMessage());
}

/* =====================================================
   9. OBTENER USUARIOS
===================================================== */

$listaUsuarios = [];

if (tienePermiso("usuarios")) {

    try {

        $consultaUsuarios = $pdo->query(
            "SELECT id, nombre, correo, rol, estado, fecha_creacion
             FROM usuarios
             ORDER BY id DESC"
        );

        $listaUsuarios = $consultaUsuarios->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        error_log("Error al consultar usuarios: " . $e->getMessage());
    }
}

/* =====================================================
   10. OBTENER CATEGORÍAS Y PRODUCTOS
===================================================== */

$listaCategorias = [];
$listaProductos = [];

if (tienePermiso("productos")) {

    try {

        $consultaCategorias = $pdo->query(
            "SELECT id_categoria, nombre
             FROM categorias
             ORDER BY nombre ASC"
        );

        $listaCategorias = $consultaCategorias->fetchAll(PDO::FETCH_ASSOC);

        $consultaProductos = $pdo->query(
            "SELECT
                p.id_producto,
                p.id_categoria,
                p.nombre,
                p.descripcion,
                p.imagen_url,
                p.activo,
                c.nombre AS categoria,
                pg.id_presentacion,
                pg.nombre_presentacion,
                pg.valor_medida,
                pg.unidad,
                pg.precio,
                pg.stock
             FROM productos p
             LEFT JOIN categorias c
                ON p.id_categoria = c.id_categoria
             LEFT JOIN presentaciones_gramaje pg
                ON p.id_producto = pg.id_producto
             ORDER BY p.id_producto DESC, pg.id_presentacion ASC"
        );

        $filasProductos = $consultaProductos->fetchAll(PDO::FETCH_ASSOC);

        foreach ($filasProductos as $fila) {

            $id = (int)$fila["id_producto"];

            if (!isset($listaProductos[$id])) {

                $listaProductos[$id] = [
                    "id_producto" => $id,
                    "id_categoria" => $fila["id_categoria"],
                    "nombre" => $fila["nombre"],
                    "descripcion" => $fila["descripcion"],
                    "imagen_url" => $fila["imagen_url"],
                    "activo" => (int)$fila["activo"],
                    "categoria" => $fila["categoria"],
                    "presentaciones" => []
                ];
            }

            if ($fila["id_presentacion"] !== null) {

                $listaProductos[$id]["presentaciones"][] = [
                    "id" => (int)$fila["id_presentacion"],
                    "nombre" => $fila["nombre_presentacion"],
                    "valor" => $fila["valor_medida"],
                    "unidad" => $fila["unidad"],
                    "precio" => $fila["precio"],
                    "stock" => $fila["stock"]
                ];
            }
        }

    } catch (PDOException $e) {
        error_log("Error al consultar productos: " . $e->getMessage());
    }
}

$listaProductos = array_values($listaProductos);

/* =====================================================
   11. MENÚ DE NAVEGACIÓN
===================================================== */

$modulos = [

    "pedidos" => [
        "nombre" => "Pedidos",
        "icono" => "fa-clipboard-list",
        "descripcion" => "Consulta y seguimiento de pedidos."
    ],

    "capturar" => [
        "nombre" => "Capturar pedidos",
        "icono" => "fa-keyboard",
        "descripcion" => "Registro de nuevos pedidos."
    ],

    "editar" => [
        "nombre" => "Editar pedidos",
        "icono" => "fa-pen-to-square",
        "descripcion" => "Modificación de pedidos existentes."
    ],

    "surtir" => [
        "nombre" => "Surtido",
        "icono" => "fa-box-open",
        "descripcion" => "Preparación y actualización de pedidos."
    ],

    "inventario" => [
        "nombre" => "Inventario",
        "icono" => "fa-boxes-stacked",
        "descripcion" => "Control de existencias y movimientos."
    ],

    "productos" => [
        "nombre" => "Productos",
        "icono" => "fa-basket-shopping",
        "descripcion" => "Administración de productos y precios."
    ],

    "usuarios" => [
        "nombre" => "Usuarios",
        "icono" => "fa-users",
        "descripcion" => "Consulta y administración de usuarios."
    ],

    "reportes" => [
        "nombre" => "Reportes",
        "icono" => "fa-chart-column",
        "descripcion" => "Consulta de información y reportes."
    ],

    "config" => [
        "nombre" => "Configuración",
        "icono" => "fa-gear",
        "descripcion" => "Configuración general del sistema."
    ],

    "seguridad" => [
        "nombre" => "Seguridad y auditoría",
        "icono" => "fa-shield-halved",
        "descripcion" => "Control de permisos y auditoría."
    ]
];

/* =====================================================
   12. VALIDAR MÓDULO SOLICITADO
===================================================== */

$moduloSeleccionado = $_GET["modulo"] ?? "inicio";

if (
    $moduloSeleccionado !== "inicio" &&
    (
        !isset($modulos[$moduloSeleccionado]) ||
        !tienePermiso($moduloSeleccionado)
    )
) {
    http_response_code(403);
    $moduloSeleccionado = "sin_acceso";
}

?>

<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Panel Administrativo | Materias Primas IA</title>

    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4.1.11"></script>

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <style>

        body {
            background: #f5f5f4;
            font-family: Arial, sans-serif;
        }

        .sidebar {
            background: linear-gradient(
                180deg,
                #1c1917,
                #292524,
                #451a03
            );
        }

        .menu-link {
            transition: .2s;
        }

        .menu-link:hover {
            background: rgba(255,255,255,.10);
        }

        .menu-active {
            background: #92400e;
            color: white;
        }

        .card {
            background: white;
            border: 1px solid #e7e5e4;
            border-radius: 16px;
        }

        .input-field {
            width: 100%;
            padding: 11px 13px;
            border: 1px solid #d6d3d1;
            border-radius: 9px;
            outline: none;
        }

        .input-field:focus {
            border-color: #b45309;
            box-shadow: 0 0 0 3px rgba(180,83,9,.12);
        }

        .btn-primary {
            background: #92400e;
            color: white;
            padding: 11px 17px;
            border-radius: 9px;
            font-weight: 600;
            transition: .2s;
        }

        .btn-primary:hover {
            background: #78350f;
        }

        .table-container {
            overflow-x: auto;
        }

        @media(max-width: 768px) {
            .sidebar {
                width: 100%;
                position: relative;
            }
        }

    </style>

</head>

<body>

<div class="min-h-screen flex flex-col md:flex-row">

    <!-- BARRA LATERAL -->

    <aside class="sidebar text-white w-full md:w-64 md:min-h-screen flex flex-col">

        <div class="p-6 border-b border-white/10">

            <h1 class="text-2xl font-bold tracking-wide">
                Materias Primas
                <span class="text-amber-400">IA</span>
            </h1>

            <p class="text-xs text-stone-400 mt-2">
                Sistema de gestión empresarial
            </p>

        </div>

        <div class="p-5 border-b border-white/10">

            <div class="flex items-center gap-3">

                <div class="w-11 h-11 rounded-full bg-amber-700 flex items-center justify-center">
                    <i class="fa-solid fa-user text-lg"></i>
                </div>

                <div class="min-w-0">

                    <p class="font-semibold truncate">
                        <?= escapar($usuarioActual) ?>
                    </p>

                    <p class="text-xs text-amber-300">
                        <?= escapar($rolActual) ?>
                    </p>

                </div>

            </div>

        </div>

        <nav class="p-4 flex-1">

            <p class="text-xs uppercase tracking-widest text-stone-500 mb-3">
                Principal
            </p>

            <a href="panel.php"
               class="menu-link <?= $moduloSeleccionado === 'inicio' ? 'menu-active' : '' ?> flex items-center gap-3 p-3 rounded-xl mb-2">

                <i class="fa-solid fa-gauge-high w-5"></i>
                <span>Dashboard</span>

            </a>

            <p class="text-xs uppercase tracking-widest text-stone-500 mt-6 mb-3">
                Módulos
            </p>

            <?php foreach ($modulos as $clave => $modulo): ?>

                <?php if (tienePermiso($clave)): ?>

                    <a href="panel.php?modulo=<?= escapar($clave) ?>"
                       class="menu-link <?= $moduloSeleccionado === $clave ? 'menu-active' : '' ?> flex items-center gap-3 p-3 rounded-xl mb-1">

                        <i class="fa-solid <?= escapar($modulo["icono"]) ?> w-5"></i>

                        <span class="text-sm">
                            <?= escapar($modulo["nombre"]) ?>
                        </span>

                    </a>

                <?php endif; ?>

            <?php endforeach; ?>

        </nav>

        <div class="p-4 border-t border-white/10">

            <a href="panel.php?salir=1"
               class="flex items-center gap-3 p-3 rounded-xl text-red-300 hover:bg-red-900/30">

                <i class="fa-solid fa-right-from-bracket"></i>
                <span>Cerrar sesión</span>

            </a>

        </div>

    </aside>


    <!-- CONTENIDO PRINCIPAL -->

    <main class="flex-1 min-w-0">

        <header class="bg-white border-b border-stone-200 px-6 py-5">

            <div class="flex flex-wrap justify-between items-center gap-3">

                <div>

                    <h2 class="text-xl font-bold text-stone-800">

                        <?= $moduloSeleccionado === "inicio"
                            ? "Dashboard"
                            : (
                                isset($modulos[$moduloSeleccionado])
                                ? escapar($modulos[$moduloSeleccionado]["nombre"])
                                : "Acceso restringido"
                            )
                        ?>

                    </h2>

                    <p class="text-sm text-stone-500 mt-1">
                        Bienvenido, <?= escapar($usuarioActual) ?>
                    </p>

                </div>

                <div class="text-sm text-stone-500">
                    <i class="fa-regular fa-calendar mr-2"></i>
                    <?= date("d/m/Y") ?>
                </div>

            </div>

        </header>


        <section class="p-5 md:p-8">

            <!-- MENSAJES -->

            <?php if ($mensaje !== ""): ?>

                <div class="mb-6 p-4 rounded-xl border
                    <?= $tipoMensaje === 'exito'
                        ? 'bg-green-50 border-green-200 text-green-800'
                        : 'bg-red-50 border-red-200 text-red-800'
                    ?>">

                    <i class="fa-solid
                        <?= $tipoMensaje === 'exito'
                            ? 'fa-circle-check'
                            : 'fa-circle-exclamation'
                        ?> mr-2"></i>

                    <?= escapar($mensaje) ?>

                </div>

            <?php endif; ?>


            <!-- =====================================
                 DASHBOARD
            ====================================== -->

            <?php if ($moduloSeleccionado === "inicio"): ?>

                <div class="mb-8">

                    <h3 class="text-2xl font-bold text-stone-800">
                        Panel de control
                    </h3>

                    <p class="text-stone-500 mt-1">
                        Accede a las herramientas disponibles para tu rol.
                    </p>

                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5 mb-8">

                    <div class="card p-5">

                        <p class="text-sm text-stone-500">
                            Usuarios registrados
                        </p>

                        <h3 class="text-3xl font-bold text-stone-800 mt-2">
                            <?= $totalUsuarios ?>
                        </h3>

                        <div class="mt-3 text-amber-800">
                            <i class="fa-solid fa-users text-xl"></i>
                        </div>

                    </div>

                    <div class="card p-5">

                        <p class="text-sm text-stone-500">
                            Supervisores
                        </p>

                        <h3 class="text-3xl font-bold text-stone-800 mt-2">
                            <?= $usuariosPorRol["supervisor"] ?? 0 ?>
                        </h3>

                        <div class="mt-3 text-indigo-700">
                            <i class="fa-solid fa-user-tie text-xl"></i>
                        </div>

                    </div>

                    <div class="card p-5">

                        <p class="text-sm text-stone-500">
                            Operadores
                        </p>

                        <h3 class="text-3xl font-bold text-stone-800 mt-2">
                            <?= $usuariosPorRol["operador"] ?? 0 ?>
                        </h3>

                        <div class="mt-3 text-emerald-700">
                            <i class="fa-solid fa-boxes-stacked text-xl"></i>
                        </div>

                    </div>

                    <div class="card p-5">

                        <p class="text-sm text-stone-500">
                            Capturistas
                        </p>

                        <h3 class="text-3xl font-bold text-stone-800 mt-2">
                            <?= $usuariosPorRol["capturista"] ?? 0 ?>
                        </h3>

                        <div class="mt-3 text-sky-700">
                            <i class="fa-solid fa-keyboard text-xl"></i>
                        </div>

                    </div>

                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">

                    <div class="card p-6">

                        <h3 class="font-bold text-stone-800 mb-4">
                            Información de la sesión
                        </h3>

                        <div class="space-y-3 text-sm">

                            <p>
                                <strong>Nombre:</strong>
                                <?= escapar($usuarioActual) ?>
                            </p>

                            <p>
                                <strong>Correo:</strong>
                                <?= escapar($correoActual) ?>
                            </p>

                            <p>
                                <strong>Rol:</strong>
                                <?= escapar($rolActual) ?>
                            </p>

                        </div>

                    </div>

                    <div class="card p-6">

                        <h3 class="font-bold text-stone-800 mb-4">
                            Tus permisos
                        </h3>

                        <div class="flex flex-wrap gap-2">

                            <?php foreach ($permisos as $permiso): ?>

                                <span class="bg-amber-100 text-amber-900 text-xs font-semibold px-3 py-2 rounded-lg">
                                    <?= escapar($permiso) ?>
                                </span>

                            <?php endforeach; ?>

                        </div>

                    </div>

                </div>


            <!-- =====================================
                 MÓDULO DE USUARIOS
            ====================================== -->

            <?php elseif ($moduloSeleccionado === "usuarios" && tienePermiso("usuarios")): ?>

                <div id="usuarios">

                    <div class="mb-6">

                        <h3 class="text-2xl font-bold text-stone-800">
                            Gestión de usuarios
                        </h3>

                        <p class="text-stone-500 mt-1">
                            Consulta las cuentas registradas en el sistema.
                        </p>

                    </div>

                    <?php if ($rol === "admin"): ?>

                        <div class="card p-6 mb-7">

                            <h3 class="text-lg font-bold text-stone-800 mb-5">
                                <i class="fa-solid fa-user-plus text-amber-700 mr-2"></i>
                                Crear usuario interno
                            </h3>

                            <form method="POST" action="panel.php?modulo=usuarios#usuarios">

                                <input type="hidden" name="accion" value="crear_usuario">
                                <input type="hidden" name="csrf_token" value="<?= escapar($csrfToken) ?>">

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">

                                    <div>

                                        <label class="block text-sm font-semibold mb-2">
                                            Nombre completo
                                        </label>

                                        <input type="text" name="nombre"
                                               class="input-field"
                                               maxlength="150" required>

                                    </div>

                                    <div>

                                        <label class="block text-sm font-semibold mb-2">
                                            Correo electrónico
                                        </label>

                                        <input type="email" name="correo"
                                               class="input-field"
                                               maxlength="150" required>

                                    </div>

                                    <div>

                                        <label class="block text-sm font-semibold mb-2">
                                            Contraseña
                                        </label>

                                        <input type="password" name="password"
                                               class="input-field"
                                               minlength="8" required>

                                    </div>

                                    <div>

                                        <label class="block text-sm font-semibold mb-2">
                                            Rol del usuario
                                        </label>

                                        <select name="rol" class="input-field" required>

                                            <option value="">Seleccionar rol</option>
                                            <option value="supervisor">Supervisor</option>
                                            <option value="operador">Operador de Almacén</option>
                                            <option value="capturista">Capturista</option>

                                        </select>

                                    </div>

                                </div>

                                <div class="mt-5">

                                    <button type="submit" class="btn-primary">
                                        <i class="fa-solid fa-plus mr-2"></i>
                                        Crear cuenta
                                    </button>

                                </div>

                            </form>

                            <p class="text-xs text-stone-500 mt-4">
                                Las cuentas de Cliente se crean desde el registro público.
                                No es posible crear otro Admin Jefe desde este formulario.
                            </p>

                        </div>

                    <?php endif; ?>


                    <!-- FILTROS -->

                    <div class="card p-5 mb-5">

                        <form method="GET" action="panel.php"
                              class="grid grid-cols-1 md:grid-cols-3 gap-4">

                            <input type="hidden" name="modulo" value="usuarios">

                            <div>

                                <label class="block text-sm font-semibold mb-2">
                                    Buscar usuario
                                </label>

                                <input type="search" name="buscar"
                                       value="<?= escapar($_GET["buscar"] ?? "") ?>"
                                       class="input-field"
                                       placeholder="Nombre o correo...">

                            </div>

                            <div>

                                <label class="block text-sm font-semibold mb-2">
                                    Filtrar por rol
                                </label>

                                <select name="filtro_rol" class="input-field">

                                    <option value="">Todos los roles</option>

                                    <?php foreach (
                                        ["admin", "supervisor", "operador", "capturista", "cliente"]
                                        as $rolFiltro
                                    ): ?>

                                        <option value="<?= escapar($rolFiltro) ?>"
                                            <?= (($_GET["filtro_rol"] ?? "") === $rolFiltro) ? "selected" : "" ?>>

                                            <?= escapar(ucfirst($rolFiltro)) ?>

                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </div>

                            <div class="flex items-end gap-2">

                                <button type="submit" class="btn-primary">
                                    <i class="fa-solid fa-magnifying-glass mr-2"></i>
                                    Buscar
                                </button>

                                <a href="panel.php?modulo=usuarios#usuarios"
                                   class="px-4 py-2.5 rounded-lg border border-stone-300 text-stone-700 hover:bg-stone-100">
                                    Limpiar
                                </a>

                            </div>

                        </form>

                    </div>


                    <!-- TABLA DE USUARIOS -->

                    <div class="card p-6">

                        <div class="mb-5">

                            <h3 class="font-bold text-stone-800">
                                Usuarios registrados
                            </h3>

                            <p class="text-xs text-stone-500 mt-1">
                                <?= count($listaUsuarios) ?> cuenta(s) encontradas
                            </p>

                        </div>

                        <div class="table-container">

                            <table class="w-full text-sm text-left">

                                <thead class="bg-stone-100 text-stone-600">

                                    <tr>
                                        <th class="p-3">ID</th>
                                        <th class="p-3">Nombre</th>
                                        <th class="p-3">Correo</th>
                                        <th class="p-3">Rol</th>
                                        <th class="p-3">Estado</th>
                                        <th class="p-3">Fecha de registro</th>

                                        <?php if ($rol === "admin"): ?>
                                            <th class="p-3">Acciones</th>
                                        <?php endif; ?>

                                    </tr>

                                </thead>

                                <tbody>

                                    <?php

                                    $buscarTexto = trim($_GET["buscar"] ?? "");
                                    $filtroRol = strtolower(trim($_GET["filtro_rol"] ?? ""));

                                    $usuariosFiltrados = array_filter(
                                        $listaUsuarios,
                                        function ($u) use ($buscarTexto, $filtroRol) {

                                            $coincideTexto =
                                                $buscarTexto === "" ||
                                                stripos($u["nombre"], $buscarTexto) !== false ||
                                                stripos($u["correo"], $buscarTexto) !== false;

                                            $coincideRol =
                                                $filtroRol === "" ||
                                                strtolower($u["rol"]) === $filtroRol;

                                            return $coincideTexto && $coincideRol;
                                        }
                                    );

                                    ?>

                                    <?php if (count($usuariosFiltrados) > 0): ?>

                                        <?php foreach ($usuariosFiltrados as $usuarioFila): ?>

                                            <?php

                                            $esAdmin = strtolower($usuarioFila["rol"]) === "admin";

                                            $esActual =
                                                (int)$usuarioFila["id"] ===
                                                (int)($_SESSION["usuario_id"] ?? 0);

                                            $estaActivo = (int)$usuarioFila["estado"] === 1;

                                            ?>

                                            <tr class="border-b border-stone-100 hover:bg-stone-50">

                                                <td class="p-3">
                                                    <?= (int)$usuarioFila["id"] ?>
                                                </td>

                                                <td class="p-3 font-semibold">
                                                    <?= escapar($usuarioFila["nombre"]) ?>
                                                </td>

                                                <td class="p-3">
                                                    <?= escapar($usuarioFila["correo"]) ?>
                                                </td>

                                                <td class="p-3">
                                                    <span class="bg-amber-100 text-amber-900 px-2 py-1 rounded-lg text-xs">
                                                        <?= escapar($usuarioFila["rol"]) ?>
                                                    </span>
                                                </td>

                                                <td class="p-3">

                                                    <span class="<?= $estaActivo
                                                        ? 'bg-green-100 text-green-800'
                                                        : 'bg-red-100 text-red-800'
                                                    ?> px-2 py-1 rounded-lg text-xs font-semibold">

                                                        <?= $estaActivo ? "Activo" : "Inactivo" ?>

                                                    </span>

                                                </td>

                                                <td class="p-3 text-stone-500">
                                                    <?= escapar($usuarioFila["fecha_creacion"]) ?>
                                                </td>

                                                <?php if ($rol === "admin"): ?>

                                                    <td class="p-3">

                                                        <?php if (!$esAdmin && !$esActual): ?>

                                                            <div class="flex flex-wrap gap-2">

                                                                <button
                                                                    type="button"
                                                                    class="px-3 py-2 rounded-lg bg-amber-100 text-amber-900 hover:bg-amber-200"
                                                                    onclick='abrirModalEditarUsuario(<?= json_encode([
                                                                        "id" => (int)$usuarioFila["id"],
                                                                        "nombre" => $usuarioFila["nombre"],
                                                                        "correo" => $usuarioFila["correo"],
                                                                        "rol" => $usuarioFila["rol"]
                                                                    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'>

                                                                    <i class="fa-solid fa-pen"></i>
                                                                    <span class="hidden xl:inline">Editar</span>

                                                                </button>

                                                                <form method="POST"
                                                                      action="panel.php?modulo=usuarios#usuarios"
                                                                      onsubmit="return confirm('¿Deseas <?= $estaActivo ? 'desactivar' : 'activar' ?> esta cuenta?');">

                                                                    <input type="hidden" name="csrf_token" value="<?= escapar($csrfToken) ?>">
                                                                    <input type="hidden" name="accion" value="cambiar_estado">
                                                                    <input type="hidden" name="id_usuario" value="<?= (int)$usuarioFila["id"] ?>">
                                                                    <input type="hidden" name="estado" value="<?= $estaActivo ? 0 : 1 ?>">

                                                                    <button type="submit"
                                                                        class="px-3 py-2 rounded-lg <?= $estaActivo
                                                                            ? 'bg-red-100 text-red-700 hover:bg-red-200'
                                                                            : 'bg-green-100 text-green-700 hover:bg-green-200'
                                                                        ?>">

                                                                        <i class="fa-solid <?= $estaActivo
                                                                            ? 'fa-user-slash'
                                                                            : 'fa-user-check'
                                                                        ?>"></i>

                                                                        <span class="hidden xl:inline">
                                                                            <?= $estaActivo ? "Desactivar" : "Activar" ?>
                                                                        </span>

                                                                    </button>

                                                                </form>

                                                            </div>

                                                        <?php else: ?>

                                                            <span class="text-xs text-stone-400">
                                                                <?= $esActual ? "Sesión actual" : "Protegido" ?>
                                                            </span>

                                                        <?php endif; ?>

                                                    </td>

                                                <?php endif; ?>

                                            </tr>

                                        <?php endforeach; ?>

                                    <?php else: ?>

                                        <tr>
                                            <td colspan="<?= $rol === 'admin' ? 7 : 6 ?>"
                                                class="p-5 text-center text-stone-500">
                                                No se encontraron usuarios con esos criterios.
                                            </td>
                                        </tr>

                                    <?php endif; ?>

                                </tbody>

                            </table>

                        </div>

                    </div>


                    <!-- MODAL EDITAR USUARIO -->

                    <?php if ($rol === "admin"): ?>

                        <div id="modalEditarUsuario"
                             class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 p-4">

                            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg p-6">

                                <div class="flex justify-between items-center mb-5">

                                    <h3 class="text-xl font-bold text-stone-800">
                                        Editar usuario
                                    </h3>

                                    <button type="button"
                                            onclick="cerrarModalEditarUsuario()"
                                            class="text-stone-500 hover:text-stone-800 text-xl">
                                        ×
                                    </button>

                                </div>

                                <form method="POST" action="panel.php?modulo=usuarios#usuarios">

                                    <input type="hidden" name="csrf_token" value="<?= escapar($csrfToken) ?>">
                                    <input type="hidden" name="accion" value="actualizar_usuario">
                                    <input type="hidden" name="id_usuario" id="editarIdUsuario">

                                    <div class="mb-4">

                                        <label class="block text-sm font-semibold mb-2">
                                            Nombre completo
                                        </label>

                                        <input type="text" name="nombre" id="editarNombre"
                                               class="input-field" maxlength="150" required>

                                    </div>

                                    <div class="mb-4">

                                        <label class="block text-sm font-semibold mb-2">
                                            Correo electrónico
                                        </label>

                                        <input type="email" name="correo" id="editarCorreo"
                                               class="input-field" maxlength="150" required>

                                    </div>

                                    <div class="mb-6">

                                        <label class="block text-sm font-semibold mb-2">
                                            Rol
                                        </label>

                                        <select name="rol" id="editarRol" class="input-field" required>
                                            <option value="supervisor">Supervisor</option>
                                            <option value="operador">Operador de Almacén</option>
                                            <option value="capturista">Capturista</option>
                                            <option value="cliente">Cliente</option>
                                        </select>

                                    </div>

                                    <div class="flex justify-end gap-3">

                                        <button type="button"
                                                onclick="cerrarModalEditarUsuario()"
                                                class="px-4 py-2 rounded-lg border border-stone-300 hover:bg-stone-100">
                                            Cancelar
                                        </button>

                                        <button type="submit" class="btn-primary">
                                            <i class="fa-solid fa-floppy-disk mr-2"></i>
                                            Guardar cambios
                                        </button>

                                    </div>

                                </form>

                            </div>

                        </div>

                    <?php endif; ?>

                </div>


            <!-- =====================================
                 MÓDULO DE PRODUCTOS
            ====================================== -->

            <?php elseif ($moduloSeleccionado === "productos" && tienePermiso("productos")): ?>

                <div id="productos">

                    <div class="mb-6">

                        <h3 class="text-2xl font-bold text-stone-800">
                            Gestión de productos
                        </h3>

                        <p class="text-stone-500 mt-1">
                            Registra productos, administra sus presentaciones y controla sus existencias.
                        </p>

                    </div>


                    <!-- FORMULARIO PARA CREAR PRODUCTO -->

                    <div class="card p-6 mb-7">

                        <h3 class="text-lg font-bold text-stone-800 mb-5">

                            <i class="fa-solid fa-circle-plus text-amber-700 mr-2"></i>

                            Registrar nuevo producto

                        </h3>

                        <form method="POST" action="panel.php?modulo=productos#productos">

                            <input type="hidden" name="csrf_token" value="<?= escapar($csrfToken) ?>">
                            <input type="hidden" name="accion" value="crear_producto">

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">

                                <div>

                                    <label class="block text-sm font-semibold mb-2">
                                        Nombre del producto
                                    </label>

                                    <input type="text"
                                           name="nombre_producto"
                                           class="input-field"
                                           maxlength="150"
                                           placeholder="Ej. Cúrcuma en Polvo"
                                           required>

                                </div>

                                <div>

                                    <label class="block text-sm font-semibold mb-2">
                                        Categoría
                                    </label>

                                    <select name="id_categoria" class="input-field">

                                        <option value="">
                                            Sin categoría
                                        </option>

                                        <?php foreach ($listaCategorias as $categoria): ?>

                                            <option value="<?= (int)$categoria["id_categoria"] ?>">
                                                <?= escapar($categoria["nombre"]) ?>
                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                </div>

                                <div class="md:col-span-2">

                                    <label class="block text-sm font-semibold mb-2">
                                        Descripción
                                    </label>

                                    <textarea name="descripcion"
                                              class="input-field"
                                              rows="3"
                                              placeholder="Descripción del producto..."></textarea>

                                </div>

                                <div class="md:col-span-2">

                                    <label class="block text-sm font-semibold mb-2">
                                        URL de imagen
                                    </label>

                                    <input type="url"
                                           name="imagen_url"
                                           class="input-field"
                                           maxlength="255"
                                           placeholder="https://ejemplo.com/imagen.jpg">

                                    <p class="text-xs text-stone-500 mt-1">
                                        Campo opcional. Introduce una dirección de imagen válida.
                                    </p>

                                </div>

                            </div>


                            <!-- PRESENTACIONES NUEVAS -->

                            <div class="mt-7">

                                <div class="flex flex-wrap justify-between items-center gap-3 mb-4">

                                    <div>

                                        <h4 class="font-bold text-stone-800">
                                            Presentaciones del producto
                                        </h4>

                                        <p class="text-xs text-stone-500 mt-1">
                                            Cada presentación tendrá su propio precio y stock.
                                        </p>

                                    </div>

                                    <button type="button"
                                            onclick="agregarPresentacion('contenedorPresentaciones')"
                                            class="px-4 py-2 rounded-lg bg-amber-100 text-amber-900 hover:bg-amber-200 font-semibold">

                                        <i class="fa-solid fa-plus mr-2"></i>
                                        Agregar presentación

                                    </button>

                                </div>

                                <div id="contenedorPresentaciones" class="space-y-4">

                                    <!-- Primera presentación -->

                                    <div class="grid grid-cols-1 md:grid-cols-5 gap-3 p-4 rounded-xl bg-stone-50 border border-stone-200">

                                        <div>
                                            <label class="block text-xs font-semibold mb-2">Nombre</label>
                                            <input type="text" name="nombre_presentacion[]" class="input-field"
                                                   placeholder="100 g" maxlength="50" required>
                                        </div>

                                        <div>
                                            <label class="block text-xs font-semibold mb-2">Medida</label>
                                            <input type="number" name="valor_medida[]" class="input-field"
                                                   min="0.01" step="0.01" placeholder="100" required>
                                        </div>

                                        <div>
                                            <label class="block text-xs font-semibold mb-2">Unidad</label>
                                            <select name="unidad[]" class="input-field" required>
                                                <option value="g">Gramos (g)</option>
                                                <option value="kg">Kilogramos (kg)</option>
                                                <option value="ml">Mililitros (ml)</option>
                                                <option value="L">Litros (L)</option>
                                                <option value="pz">Pieza (pz)</option>
                                            </select>
                                        </div>

                                        <div>
                                            <label class="block text-xs font-semibold mb-2">Precio ($)</label>
                                            <input type="number" name="precio[]" class="input-field"
                                                   min="0" step="0.01" placeholder="25.00" required>
                                        </div>

                                        <div>
                                            <label class="block text-xs font-semibold mb-2">Stock</label>
                                            <input type="number" name="stock[]" class="input-field"
                                                   min="0" step="0.01" value="0" required>
                                        </div>

                                    </div>

                                </div>

                            </div>

                            <div class="mt-6">

                                <button type="submit" class="btn-primary">
                                    <i class="fa-solid fa-floppy-disk mr-2"></i>
                                    Registrar producto
                                </button>

                            </div>

                        </form>

                    </div>


                    <!-- LISTADO DE PRODUCTOS -->

                    <div class="card p-6">

                        <div class="flex flex-wrap justify-between items-center gap-3 mb-5">

                            <div>

                                <h3 class="font-bold text-stone-800">
                                    Productos registrados
                                </h3>

                                <p class="text-xs text-stone-500 mt-1">
                                    <?= count($listaProductos) ?> producto(s) encontrados
                                </p>

                            </div>

                            <input type="search"
                                   id="buscarProducto"
                                   class="input-field max-w-xs"
                                   placeholder="Buscar producto...">

                        </div>

                        <div class="table-container">

                            <table class="w-full text-sm text-left">

                                <thead class="bg-stone-100 text-stone-600">

                                    <tr>
                                        <th class="p-3">ID</th>
                                        <th class="p-3">Producto</th>
                                        <th class="p-3">Categoría</th>
                                        <th class="p-3">Presentaciones</th>
                                        <th class="p-3">Stock total</th>
                                        <th class="p-3">Estado</th>
                                        <th class="p-3">Acciones</th>
                                    </tr>

                                </thead>

                                <tbody id="tablaProductos">

                                    <?php if (count($listaProductos) > 0): ?>

                                        <?php foreach ($listaProductos as $producto): ?>

                                            <?php

                                            $stockTotal = 0;

                                            foreach ($producto["presentaciones"] as $presentacion) {
                                                $stockTotal += (float)$presentacion["stock"];
                                            }

                                            ?>

                                            <tr class="border-b border-stone-100 hover:bg-stone-50 fila-producto"
                                                data-busqueda="<?= escapar(
                                                    strtolower(
                                                        $producto["nombre"] . " " .
                                                        ($producto["categoria"] ?? "")
                                                    )
                                                ) ?>">

                                                <td class="p-3">
                                                    <?= (int)$producto["id_producto"] ?>
                                                </td>

                                                <td class="p-3">

                                                    <div class="font-semibold text-stone-800">
                                                        <?= escapar($producto["nombre"]) ?>
                                                    </div>

                                                    <div class="text-xs text-stone-500 mt-1">
                                                        <?= escapar($producto["descripcion"]) ?>
                                                    </div>

                                                </td>

                                                <td class="p-3">
                                                    <?= escapar($producto["categoria"] ?? "Sin categoría") ?>
                                                </td>

                                                <td class="p-3">
                                                    <?= count($producto["presentaciones"]) ?>
                                                </td>

                                                <td class="p-3 font-semibold">
                                                    <?= number_format($stockTotal, 2) ?>
                                                </td>

                                                <td class="p-3">

                                                    <span class="<?= $producto["activo"]
                                                        ? 'bg-green-100 text-green-800'
                                                        : 'bg-red-100 text-red-800'
                                                    ?> px-2 py-1 rounded-lg text-xs font-semibold">

                                                        <?= $producto["activo"] ? "Activo" : "Inactivo" ?>

                                                    </span>

                                                </td>

                                                <td class="p-3">

                                                    <div class="flex flex-wrap gap-2">

                                                        <button
                                                            type="button"
                                                            onclick='abrirModalEditarProducto(<?= json_encode(
                                                                $producto,
                                                                JSON_HEX_TAG |
                                                                JSON_HEX_APOS |
                                                                JSON_HEX_AMP |
                                                                JSON_HEX_QUOT
                                                            ) ?>)'
                                                            class="px-3 py-2 rounded-lg bg-amber-100 text-amber-900 hover:bg-amber-200">

                                                            <i class="fa-solid fa-pen"></i>
                                                            Editar

                                                        </button>

                                                        <form method="POST"
                                                              action="panel.php?modulo=productos#productos"
                                                              onsubmit="return confirm('¿Deseas <?= $producto["activo"] ? 'desactivar' : 'activar' ?> este producto?');">

                                                            <input type="hidden" name="csrf_token" value="<?= escapar($csrfToken) ?>">
                                                            <input type="hidden" name="accion" value="cambiar_estado_producto">
                                                            <input type="hidden" name="id_producto" value="<?= (int)$producto["id_producto"] ?>">
                                                            <input type="hidden" name="estado" value="<?= $producto["activo"] ? 0 : 1 ?>">

                                                            <button type="submit"
                                                                    class="px-3 py-2 rounded-lg <?= $producto["activo"]
                                                                        ? 'bg-red-100 text-red-700 hover:bg-red-200'
                                                                        : 'bg-green-100 text-green-700 hover:bg-green-200'
                                                                    ?>">

                                                                <i class="fa-solid <?= $producto["activo"]
                                                                    ? 'fa-toggle-off'
                                                                    : 'fa-toggle-on'
                                                                ?>"></i>

                                                                <?= $producto["activo"] ? "Desactivar" : "Activar" ?>

                                                            </button>

                                                        </form>

                                                    </div>

                                                </td>

                                            </tr>

                                        <?php endforeach; ?>

                                    <?php else: ?>

                                        <tr>
                                            <td colspan="7" class="p-6 text-center text-stone-500">
                                                Todavía no hay productos registrados.
                                            </td>
                                        </tr>

                                    <?php endif; ?>

                                </tbody>

                            </table>

                        </div>

                    </div>


                    <!-- MODAL EDITAR PRODUCTO -->

                    <div id="modalEditarProducto"
                         class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 p-4 overflow-y-auto">

                        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-5xl p-6 my-8">

                            <div class="flex justify-between items-center mb-5">

                                <h3 class="text-xl font-bold text-stone-800">
                                    Editar producto
                                </h3>

                                <button type="button"
                                        onclick="cerrarModalEditarProducto()"
                                        class="text-stone-500 hover:text-stone-800 text-2xl">
                                    ×
                                </button>

                            </div>

                            <form method="POST" action="panel.php?modulo=productos#productos">

                                <input type="hidden" name="csrf_token" value="<?= escapar($csrfToken) ?>">
                                <input type="hidden" name="accion" value="actualizar_producto">
                                <input type="hidden" name="id_producto" id="editarIdProducto">

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">

                                    <div>

                                        <label class="block text-sm font-semibold mb-2">
                                            Nombre del producto
                                        </label>

                                        <input type="text"
                                               name="nombre_producto"
                                               id="editarNombreProducto"
                                               class="input-field"
                                               maxlength="150"
                                               required>

                                    </div>

                                    <div>

                                        <label class="block text-sm font-semibold mb-2">
                                            Categoría
                                        </label>

                                        <select name="id_categoria"
                                                id="editarCategoriaProducto"
                                                class="input-field">

                                            <option value="">
                                                Sin categoría
                                            </option>

                                            <?php foreach ($listaCategorias as $categoria): ?>

                                                <option value="<?= (int)$categoria["id_categoria"] ?>">
                                                    <?= escapar($categoria["nombre"]) ?>
                                                </option>

                                            <?php endforeach; ?>

                                        </select>

                                    </div>

                                    <div class="md:col-span-2">

                                        <label class="block text-sm font-semibold mb-2">
                                            Descripción
                                        </label>

                                        <textarea name="descripcion"
                                                  id="editarDescripcionProducto"
                                                  class="input-field"
                                                  rows="3"></textarea>

                                    </div>

                                    <div class="md:col-span-2">

                                        <label class="block text-sm font-semibold mb-2">
                                            URL de imagen
                                        </label>

                                        <input type="url"
                                               name="imagen_url"
                                               id="editarImagenProducto"
                                               class="input-field"
                                               maxlength="255">

                                    </div>

                                </div>


                                <div class="mt-7">

                                    <div class="flex flex-wrap justify-between items-center gap-3 mb-4">

                                        <h4 class="font-bold text-stone-800">
                                            Presentaciones y existencias
                                        </h4>

                                        <button type="button"
                                                onclick="agregarPresentacion('contenedorPresentacionesEditar', true)"
                                                class="px-4 py-2 rounded-lg bg-amber-100 text-amber-900 hover:bg-amber-200 font-semibold">

                                            <i class="fa-solid fa-plus mr-2"></i>
                                            Agregar presentación

                                        </button>

                                    </div>

                                    <div id="contenedorPresentacionesEditar" class="space-y-4"></div>

                                    <p class="text-xs text-stone-500 mt-3">
                                        Las presentaciones existentes se actualizan, pero no se eliminan desde este formulario.
                                        Esto ayuda a conservar las referencias de los pedidos anteriores.
                                    </p>

                                </div>

                                <div class="flex justify-end gap-3 mt-7">

                                    <button type="button"
                                            onclick="cerrarModalEditarProducto()"
                                            class="px-4 py-2 rounded-lg border border-stone-300 hover:bg-stone-100">
                                        Cancelar
                                    </button>

                                    <button type="submit" class="btn-primary">
                                        <i class="fa-solid fa-floppy-disk mr-2"></i>
                                        Guardar cambios
                                    </button>

                                </div>

                            </form>

                        </div>

                    </div>

                </div>


            <!-- =====================================
                 MÓDULOS PENDIENTES
            ====================================== -->

            <?php elseif (isset($modulos[$moduloSeleccionado])): ?>

                <div class="card p-8 text-center">

                    <div class="w-16 h-16 rounded-2xl bg-amber-100 text-amber-800 flex items-center justify-center mx-auto mb-5">

                        <i class="fa-solid <?= escapar($modulos[$moduloSeleccionado]["icono"]) ?> text-2xl"></i>

                    </div>

                    <h3 class="text-xl font-bold text-stone-800 mb-2">
                        <?= escapar($modulos[$moduloSeleccionado]["nombre"]) ?>
                    </h3>

                    <p class="text-stone-500 mb-5">
                        <?= escapar($modulos[$moduloSeleccionado]["descripcion"]) ?>
                    </p>

                    <span class="inline-block bg-amber-100 text-amber-900 text-xs font-semibold px-4 py-2 rounded-full">
                        Módulo pendiente de integración
                    </span>

                    <p class="text-sm text-stone-500 mt-5 max-w-xl mx-auto">
                        Esta sección ya está contemplada en el sistema de permisos.
                        Posteriormente conectaremos su interfaz y sus operaciones con la base de datos.
                    </p>

                </div>


            <!-- ACCESO RESTRINGIDO -->

            <?php else: ?>

                <div class="card p-10 text-center">

                    <i class="fa-solid fa-lock text-4xl text-red-700 mb-4"></i>

                    <h3 class="text-xl font-bold text-stone-800">
                        Acceso restringido
                    </h3>

                    <p class="text-stone-500 mt-2">
                        Tu rol no tiene permiso para acceder a esta sección.
                    </p>

                    <a href="panel.php" class="btn-primary inline-block mt-5">
                        Volver al dashboard
                    </a>

                </div>

            <?php endif; ?>

        </section>


        <footer class="px-6 py-5 text-center text-xs text-stone-400">
            Materias Primas IA · Sistema de gestión empresarial
        </footer>

    </main>

</div>


<!-- =================================================
     JAVASCRIPT
================================================== -->

<script>

/* ================================================
   MODAL DE USUARIOS
================================================ */

function abrirModalEditarUsuario(usuario) {

    document.getElementById("editarIdUsuario").value = usuario.id;
    document.getElementById("editarNombre").value = usuario.nombre;
    document.getElementById("editarCorreo").value = usuario.correo;
    document.getElementById("editarRol").value = usuario.rol;

    const modal = document.getElementById("modalEditarUsuario");

    modal.classList.remove("hidden");
    modal.classList.add("flex");
}

function cerrarModalEditarUsuario() {

    const modal = document.getElementById("modalEditarUsuario");

    if (!modal) return;

    modal.classList.add("hidden");
    modal.classList.remove("flex");
}


/* ================================================
   AGREGAR PRESENTACIONES
================================================ */

function agregarPresentacion(contenedorId, esEdicion = false, datos = null) {

    const contenedor = document.getElementById(contenedorId);

    if (!contenedor) return;

    const fila = document.createElement("div");

    fila.className =
        "grid grid-cols-1 md:grid-cols-5 gap-3 p-4 rounded-xl bg-stone-50 border border-stone-200";

    const id = datos ? datos.id : 0;
    const nombre = datos ? datos.nombre : "";
    const valor = datos ? datos.valor : "";
    const unidad = datos ? datos.unidad : "g";
    const precio = datos ? datos.precio : "";
    const stock = datos ? datos.stock : "0";

    const hiddenId = esEdicion
        ? `<input type="hidden" name="id_presentacion[]" value="${id}">`
        : "";

    fila.innerHTML = `

        <div>
            ${hiddenId}
            <label class="block text-xs font-semibold mb-2">Nombre</label>
            <input type="text"
                   name="nombre_presentacion[]"
                   class="input-field"
                   maxlength="50"
                   required>
        </div>

        <div>
            <label class="block text-xs font-semibold mb-2">Medida</label>
            <input type="number"
                   name="valor_medida[]"
                   class="input-field"
                   min="0.01"
                   step="0.01"
                   required>
        </div>

        <div>
            <label class="block text-xs font-semibold mb-2">Unidad</label>
            <select name="unidad[]" class="input-field" required>
                <option value="g">Gramos (g)</option>
                <option value="kg">Kilogramos (kg)</option>
                <option value="ml">Mililitros (ml)</option>
                <option value="L">Litros (L)</option>
                <option value="pz">Pieza (pz)</option>
            </select>
        </div>

        <div>
            <label class="block text-xs font-semibold mb-2">Precio ($)</label>
            <input type="number"
                   name="precio[]"
                   class="input-field"
                   min="0"
                   step="0.01"
                   required>
        </div>

        <div>
            <label class="block text-xs font-semibold mb-2">Stock</label>
            <input type="number"
                   name="stock[]"
                   class="input-field"
                   min="0"
                   step="0.01"
                   required>
        </div>

    `;

    const campos = fila.querySelectorAll("input, select");

    campos.forEach(campo => {

        if (campo.name === "nombre_presentacion[]") {
            campo.value = nombre;
        }

        if (campo.name === "valor_medida[]") {
            campo.value = valor;
        }

        if (campo.name === "unidad[]") {
            campo.value = unidad;
        }

        if (campo.name === "precio[]") {
            campo.value = precio;
        }

        if (campo.name === "stock[]") {
            campo.value = stock;
        }

    });

    /*
     * Las presentaciones existentes no se eliminan desde
     * el formulario de edición. Solo se puede retirar una
     * fila nueva que todavía no se ha guardado.
     */

    if (esEdicion && id === 0) {

        const boton = document.createElement("button");

        boton.type = "button";
        boton.className =
            "md:col-span-5 text-sm text-red-700 hover:text-red-900 text-left";

        boton.innerHTML =
            '<i class="fa-solid fa-trash mr-2"></i>Quitar presentación nueva';

        boton.addEventListener("click", function() {
            fila.remove();
        });

        fila.appendChild(boton);
    }

    contenedor.appendChild(fila);
}


/* ================================================
   MODAL EDITAR PRODUCTO
================================================ */

function abrirModalEditarProducto(producto) {

    document.getElementById("editarIdProducto").value =
        producto.id_producto;

    document.getElementById("editarNombreProducto").value =
        producto.nombre;

    document.getElementById("editarCategoriaProducto").value =
        producto.id_categoria ?? "";

    document.getElementById("editarDescripcionProducto").value =
        producto.descripcion ?? "";

    document.getElementById("editarImagenProducto").value =
        producto.imagen_url ?? "";

    const contenedor = document.getElementById(
        "contenedorPresentacionesEditar"
    );

    contenedor.innerHTML = "";

    if (
        Array.isArray(producto.presentaciones) &&
        producto.presentaciones.length > 0
    ) {

        producto.presentaciones.forEach(presentacion => {

            agregarPresentacion(
                "contenedorPresentacionesEditar",
                true,
                presentacion
            );

        });

    } else {

        agregarPresentacion(
            "contenedorPresentacionesEditar",
            true
        );

    }

    const modal = document.getElementById("modalEditarProducto");

    modal.classList.remove("hidden");
    modal.classList.add("flex");
}

function cerrarModalEditarProducto() {

    const modal = document.getElementById("modalEditarProducto");

    if (!modal) return;

    modal.classList.add("hidden");
    modal.classList.remove("flex");
}


/* ================================================
   BUSCADOR DE PRODUCTOS
================================================ */

const buscadorProducto = document.getElementById("buscarProducto");

if (buscadorProducto) {

    buscadorProducto.addEventListener("input", function() {

        const texto = this.value.toLowerCase().trim();

        document.querySelectorAll(".fila-producto").forEach(fila => {

            const contenido = fila.dataset.busqueda || "";

            fila.style.display = contenido.includes(texto)
                ? ""
                : "none";

        });

    });

}


/* ================================================
   CERRAR MODALES CON ESCAPE
================================================ */

document.addEventListener("keydown", function(evento) {

    if (evento.key === "Escape") {

        cerrarModalEditarUsuario();
        cerrarModalEditarProducto();

    }

});

</script>

</body>
</html>
