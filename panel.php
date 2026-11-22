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
   2. ROLES Y PERMISOS (4 NIVELES)
===================================================== */

$permisosDisponibles = [
    "pedidos", "capturar", "editar", "surtir",
    "inventario", "productos", "usuarios",
    "reportes", "config", "borrar",
    "seguridad", "tienda"
];

$roles = [
    "admin" => [
        "nombre" => "Admin Jefe (Nivel 4)",
        "nivel" => 4,
        "permisos" => $permisosDisponibles
    ],

    "supervisor" => [
        "nombre" => "Supervisor (Nivel 3)",
        "nivel" => 3,
        "permisos" => array_values(
            array_diff($permisosDisponibles, ["seguridad", "borrar"])
        )
    ],

    "operador" => [
        "nombre" => "Operador (Nivel 2)",
        "nivel" => 2,
        "permisos" => ["pedidos", "capturar", "editar", "surtir", "inventario"]
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
    $ids = $_POST["id_presentacion"] ?? [];

    if (
        !is_array($nombres) ||
        !is_array($valores) ||
        !is_array($unidades) ||
        !is_array($precios) ||
        ($esEdicion && !is_array($ids))
    ) {
        throw new Exception("Los datos de las presentaciones no son válidos.");
    }

    $total = count($nombres);

    if ($total < 1 || $total > 30) {
        throw new Exception("Debes registrar entre 1 y 30 presentaciones.");
    }

    $resultado = [];

    for ($i = 0; $i < $total; $i++) {

        $nombre = trim((string)($nombres[$i] ?? ""));
        $valor = $valores[$i] ?? "";
        $unidad = trim((string)($unidades[$i] ?? ""));
        $precio = $precios[$i] ?? "";

        if (
            $nombre === "" ||
            $unidad === "" ||
            !is_numeric($valor) ||
            !is_numeric($precio)
        ) {
            throw new Exception("Completa correctamente todas las presentaciones.");
        }

        $valor = (float)$valor;
        $precio = (float)$precio;

        if (
            $valor <= 0 ||
            $precio < 0 ||
            !is_finite($valor) ||
            !is_finite($precio)
        ) {
            throw new Exception("Revisa las medidas y los precios de las presentaciones.");
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
            if ($id < 0) {
                throw new Exception("El identificador de una presentación no es válido.");
            }
        }

        $resultado[] = [
            "id" => $id,
            "nombre" => $nombre,
            "valor" => $valor,
            "unidad" => $unidad,
            "precio" => $precio
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
                    "operador"
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
                    VALUES (?, ?, ?, ?, ?, 0)"
                );

                foreach ($presentaciones as $p) {

                    $insertarPresentacion->execute([
                        $idProducto,
                        $p["nombre"],
                        $p["valor"],
                        $p["unidad"],
                        $p["precio"]
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
                         precio = ?
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
                    VALUES (?, ?, ?, ?, ?, 0)"
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
                            $p["id"],
                            $idProducto
                        ]);

                    } else {

                        $insertarPresentacion->execute([
                            $idProducto,
                            $p["nombre"],
                            $p["valor"],
                            $p["unidad"],
                            $p["precio"]
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


        /* =============================================
           OPERACIONES DE INVENTARIO
        ============================================= */

        elseif (in_array(
            $accion,
            ["registrar_movimiento_inventario"],
            true
        )) {

            if (!tienePermiso("inventario")) {
                http_response_code(403);
                throw new Exception("No tienes autorización para administrar el inventario.");
            }

            $idPresentacion = filter_var(
                $_POST["id_presentacion"] ?? null,
                FILTER_VALIDATE_INT
            );

            $tipoMovimiento = trim($_POST["tipo_movimiento"] ?? "");
            $cantidadCapturada = $_POST["cantidad"] ?? "";
            $motivo = trim($_POST["motivo"] ?? "");

            if (!$idPresentacion || $idPresentacion < 1) {
                throw new Exception("Selecciona una presentación válida.");
            }

            if (!in_array($tipoMovimiento, ["Entrada", "Salida", "Ajuste"], true)) {
                throw new Exception("El tipo de movimiento no es válido.");
            }

            if (
                !is_numeric($cantidadCapturada) ||
                !is_finite((float)$cantidadCapturada) ||
                (float)$cantidadCapturada < 0
            ) {
                throw new Exception("Introduce una cantidad numérica válida.");
            }

            $cantidadCapturada = round((float)$cantidadCapturada, 2);

            if ($tipoMovimiento !== "Ajuste" && $cantidadCapturada <= 0) {
                throw new Exception("La cantidad debe ser mayor que cero.");
            }

            if ($tipoMovimiento === "Ajuste" && $cantidadCapturada < 0) {
                throw new Exception("El stock objetivo no puede ser negativo.");
            }

            if (mb_strlen($motivo) > 255) {
                throw new Exception("El motivo no puede superar los 255 caracteres.");
            }

            $pdo->beginTransaction();

            $buscarStock = $pdo->prepare(
                "SELECT
                    pg.id_presentacion,
                    pg.stock,
                    pg.nombre_presentacion,
                    p.nombre AS nombre_producto
                 FROM presentaciones_gramaje pg
                 INNER JOIN productos p
                    ON p.id_producto = pg.id_producto
                 WHERE pg.id_presentacion = :id
                 FOR UPDATE"
            );

            $buscarStock->execute([":id" => $idPresentacion]);
            $presentacion = $buscarStock->fetch();

            if (!$presentacion) {
                throw new Exception("La presentación seleccionada no existe.");
            }

            $stockAnterior = round((float)($presentacion["stock"] ?? 0), 2);

            if ($tipoMovimiento === "Entrada") {
                $stockNuevo = round($stockAnterior + $cantidadCapturada, 2);
                $cantidadMovimiento = $cantidadCapturada;
            } elseif ($tipoMovimiento === "Salida") {
                if ($cantidadCapturada > $stockAnterior) {
                    throw new Exception(
                        "No hay existencias suficientes. Stock disponible: " .
                        number_format($stockAnterior, 2)
                    );
                }

                $stockNuevo = round($stockAnterior - $cantidadCapturada, 2);
                $cantidadMovimiento = $cantidadCapturada;
            } else {
<<<<<<< HEAD
=======
                // En un ajuste, la cantidad capturada representa el stock real/objetivo.
>>>>>>> 6ede5fe4a49d6fe8667faa811b6bff0b79e43461
                $stockNuevo = $cantidadCapturada;
                $cantidadMovimiento = round(abs($stockNuevo - $stockAnterior), 2);

                if ($cantidadMovimiento <= 0) {
                    throw new Exception("El stock objetivo es igual al stock actual; no hay cambios que registrar.");
                }
            }

            if ($stockNuevo < 0 || $stockNuevo > 99999999.99) {
                throw new Exception("El stock resultante está fuera del rango permitido.");
            }

            $actualizarStock = $pdo->prepare(
                "UPDATE presentaciones_gramaje
                 SET stock = :stock
                 WHERE id_presentacion = :id"
            );

            $actualizarStock->execute([
                ":stock" => number_format($stockNuevo, 2, ".", ""),
                ":id" => $idPresentacion
            ]);

            $insertarMovimiento = $pdo->prepare(
                "INSERT INTO movimientos_inventario
                (
                    id_presentacion,
                    tipo,
                    cantidad,
                    stock_anterior,
                    stock_nuevo,
                    motivo,
                    id_usuario
                )
                VALUES
                (
                    :id_presentacion,
                    :tipo,
                    :cantidad,
                    :stock_anterior,
                    :stock_nuevo,
                    :motivo,
                    :id_usuario
                )"
            );

            $motivoFinal = $motivo !== ""
                ? $motivo
                : (
                    $tipoMovimiento === "Ajuste"
                    ? "Ajuste de existencias"
                    : "Movimiento de inventario"
                );

            $insertarMovimiento->execute([
                ":id_presentacion" => $idPresentacion,
                ":tipo" => $tipoMovimiento,
                ":cantidad" => number_format($cantidadMovimiento, 2, ".", ""),
                ":stock_anterior" => number_format($stockAnterior, 2, ".", ""),
                ":stock_nuevo" => number_format($stockNuevo, 2, ".", ""),
                ":motivo" => $motivoFinal,
                ":id_usuario" => !empty($_SESSION["usuario_id"])
                    ? (int)$_SESSION["usuario_id"]
                    : null
            ]);

            $pdo->commit();

            redirigir("inventario", "movimiento_registrado");
        }

        /* =============================================
           SURTIR ORDEN DE COMPRA
        ============================================= */

        elseif ($accion === "confirmar_surtido") {

            if (!tienePermiso("surtir")) {
                http_response_code(403);
                throw new Exception("No tienes autorización para surtir pedidos.");
            }

            $idOrden = filter_var(
                $_POST["id_orden"] ?? null,
                FILTER_VALIDATE_INT
            );

            if (!$idOrden || $idOrden < 1) {
                throw new Exception("La orden seleccionada no es válida.");
            }

            $pdo->beginTransaction();

<<<<<<< HEAD
=======
            // Bloquea la orden para impedir que dos usuarios la surtan al mismo tiempo.
>>>>>>> 6ede5fe4a49d6fe8667faa811b6bff0b79e43461
            $buscarOrden = $pdo->prepare(
                "SELECT id_orden, estado
                 FROM ordenes_compra
                 WHERE id_orden = :id
                 FOR UPDATE"
            );
            $buscarOrden->execute([":id" => $idOrden]);
            $ordenBloqueada = $buscarOrden->fetch(PDO::FETCH_ASSOC);

            if (!$ordenBloqueada) {
                throw new Exception("La orden de compra no existe.");
            }

            if ($ordenBloqueada["estado"] !== "Pendiente") {
                throw new Exception("Esta orden ya no está pendiente y no puede surtirse nuevamente.");
            }

            $buscarDetalles = $pdo->prepare(
                "SELECT id_detalle, id_presentacion, cantidad
                 FROM detalle_orden
                 WHERE id_orden = :id
                 ORDER BY id_presentacion ASC, id_detalle ASC"
            );
            $buscarDetalles->execute([":id" => $idOrden]);
            $detallesOrden = $buscarDetalles->fetchAll(PDO::FETCH_ASSOC);

            if (!$detallesOrden) {
                throw new Exception("La orden no contiene productos para surtir.");
            }

<<<<<<< HEAD
=======
            // Agrupa líneas repetidas de una misma presentación para validar y descontar el total.
>>>>>>> 6ede5fe4a49d6fe8667faa811b6bff0b79e43461
            $cantidadesPorPresentacion = [];
            foreach ($detallesOrden as $detalle) {
                $idPres = (int)($detalle["id_presentacion"] ?? 0);
                $cantidadLinea = (float)($detalle["cantidad"] ?? 0);

                if ($idPres < 1 || !is_finite($cantidadLinea) || $cantidadLinea <= 0) {
                    throw new Exception("La orden contiene una presentación o cantidad no válida.");
                }

                if (!isset($cantidadesPorPresentacion[$idPres])) {
                    $cantidadesPorPresentacion[$idPres] = 0.0;
                }
                $cantidadesPorPresentacion[$idPres] += $cantidadLinea;
            }

            ksort($cantidadesPorPresentacion, SORT_NUMERIC);
            $idsPresentaciones = array_keys($cantidadesPorPresentacion);
            $marcadores = implode(",", array_fill(0, count($idsPresentaciones), "?"));

<<<<<<< HEAD
=======
            // Bloquea las filas de inventario en orden determinista.
>>>>>>> 6ede5fe4a49d6fe8667faa811b6bff0b79e43461
            $bloquearPresentaciones = $pdo->prepare(
                "SELECT id_presentacion, stock
                 FROM presentaciones_gramaje
                 WHERE id_presentacion IN ($marcadores)
                 ORDER BY id_presentacion ASC
                 FOR UPDATE"
            );
            $bloquearPresentaciones->execute($idsPresentaciones);
            $stocksBloqueados = $bloquearPresentaciones->fetchAll(PDO::FETCH_ASSOC);

            $stockPorPresentacion = [];
            foreach ($stocksBloqueados as $filaStock) {
                $stockPorPresentacion[(int)$filaStock["id_presentacion"]] =
                    round((float)$filaStock["stock"], 2);
            }

<<<<<<< HEAD
=======
            // Se valida todo antes de modificar cualquier existencia.
>>>>>>> 6ede5fe4a49d6fe8667faa811b6bff0b79e43461
            foreach ($cantidadesPorPresentacion as $idPres => $cantidadNecesaria) {
                if (!array_key_exists($idPres, $stockPorPresentacion)) {
                    throw new Exception("Una presentación de la orden ya no existe en el inventario.");
                }

                $disponible = $stockPorPresentacion[$idPres];
                if ($cantidadNecesaria > $disponible) {
                    throw new Exception(
                        "Inventario insuficiente para la presentación #" . $idPres .
                        ". Solicitado: " . number_format($cantidadNecesaria, 2) .
                        "; disponible: " . number_format($disponible, 2) . "."
                    );
                }
            }

            $actualizarStockSurtido = $pdo->prepare(
                "UPDATE presentaciones_gramaje
                 SET stock = :stock
                 WHERE id_presentacion = :id"
            );

            $insertarMovimientoSurtido = $pdo->prepare(
                "INSERT INTO movimientos_inventario
                 (id_presentacion, tipo, cantidad, stock_anterior, stock_nuevo,
                  motivo, id_orden, id_usuario)
                 VALUES (:id_presentacion, 'Surtido', :cantidad, :anterior,
                         :nuevo, :motivo, :id_orden, :id_usuario)"
            );

            foreach ($cantidadesPorPresentacion as $idPres => $cantidadNecesaria) {
                $stockAnterior = $stockPorPresentacion[$idPres];
                $stockNuevo = round($stockAnterior - $cantidadNecesaria, 2);

                $actualizarStockSurtido->execute([
                    ":stock" => number_format($stockNuevo, 2, ".", ""),
                    ":id" => $idPres
                ]);

                $insertarMovimientoSurtido->execute([
                    ":id_presentacion" => $idPres,
                    ":cantidad" => number_format($cantidadNecesaria, 2, ".", ""),
                    ":anterior" => number_format($stockAnterior, 2, ".", ""),
                    ":nuevo" => number_format($stockNuevo, 2, ".", ""),
                    ":motivo" => "Surtido de orden #" . $idOrden,
                    ":id_orden" => $idOrden,
                    ":id_usuario" => !empty($_SESSION["usuario_id"])
                        ? (int)$_SESSION["usuario_id"]
                        : null
                ]);
            }

<<<<<<< HEAD
=======
            // Actualización condicional adicional como protección contra doble surtido.
>>>>>>> 6ede5fe4a49d6fe8667faa811b6bff0b79e43461
            $actualizarEstadoOrden = $pdo->prepare(
                "UPDATE ordenes_compra
                 SET estado = 'Surtido'
                 WHERE id_orden = :id AND estado = 'Pendiente'"
            );
            $actualizarEstadoOrden->execute([":id" => $idOrden]);

            if ($actualizarEstadoOrden->rowCount() !== 1) {
                throw new Exception("No se pudo actualizar el estado de la orden. No se aplicaron cambios.");
            }

            $pdo->commit();
            redirigir("surtir", "orden_surtida");
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
    "producto_estado" => "El estado del producto se actualizó correctamente.",
    "movimiento_registrado" => "El movimiento de inventario se registró correctamente.",
    "orden_surtida" => "La orden se surtió correctamente y el inventario fue actualizado."
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
   11. OBTENER INVENTARIO E HISTORIAL DE MOVIMIENTOS
===================================================== */

$listaInventario = [];
$historialInventario = [];

if (tienePermiso("inventario")) {

    try {

        $consultaInventario = $pdo->query(
            "SELECT
                pg.id_presentacion,
                pg.id_producto,
                pg.nombre_presentacion,
                pg.valor_medida,
                pg.unidad,
                pg.precio,
                COALESCE(pg.stock, 0) AS stock,
                p.nombre AS nombre_producto,
                c.nombre AS categoria
             FROM presentaciones_gramaje pg
             INNER JOIN productos p
                ON p.id_producto = pg.id_producto
             LEFT JOIN categorias c
                ON c.id_categoria = p.id_categoria
             WHERE p.activo = 1
             ORDER BY p.nombre ASC, pg.valor_medida ASC, pg.id_presentacion ASC"
        );

        $listaInventario = $consultaInventario->fetchAll(PDO::FETCH_ASSOC);

        $consultaHistorial = $pdo->query(
            "SELECT
                mi.id_movimiento,
                mi.tipo,
                mi.cantidad,
                mi.stock_anterior,
                mi.stock_nuevo,
                mi.motivo,
                mi.id_orden,
                mi.fecha_movimiento,
                pg.nombre_presentacion,
                p.nombre AS nombre_producto,
                u.nombre AS nombre_usuario
             FROM movimientos_inventario mi
             INNER JOIN presentaciones_gramaje pg
                ON pg.id_presentacion = mi.id_presentacion
             INNER JOIN productos p
                ON p.id_producto = pg.id_producto
             LEFT JOIN usuarios u
                ON u.id = mi.id_usuario
             ORDER BY mi.id_movimiento DESC
             LIMIT 100"
        );

        $historialInventario = $consultaHistorial->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        error_log("Error al consultar inventario: " . $e->getMessage());
    }
}


/* =====================================================
   11. ÓRDENES PENDIENTES PARA SURTIDO
===================================================== */

$ordenesPendientes = [];
$detallesPendientes = [];

if (tienePermiso("surtir")) {
    try {
        $consultaOrdenesPendientes = $pdo->query(
            "SELECT
                oc.id_orden,
                oc.id_cliente,
                oc.fecha_creacion,
                oc.estado,
                oc.subtotal,
                oc.descuento_aplicado,
                oc.total_final,
                oc.observaciones,
                COUNT(do.id_detalle) AS total_lineas
             FROM ordenes_compra oc
             LEFT JOIN detalle_orden do ON do.id_orden = oc.id_orden
             WHERE oc.estado = 'Pendiente'
             GROUP BY oc.id_orden, oc.id_cliente, oc.fecha_creacion,
                      oc.estado, oc.subtotal, oc.descuento_aplicado,
                      oc.total_final, oc.observaciones
             ORDER BY oc.fecha_creacion ASC, oc.id_orden ASC"
        );
        $ordenesPendientes = $consultaOrdenesPendientes->fetchAll(PDO::FETCH_ASSOC);

        if ($ordenesPendientes) {
            $idsOrdenesPendientes = array_map(
                static fn($orden) => (int)$orden["id_orden"],
                $ordenesPendientes
            );
            $marcadoresOrdenes = implode(",", array_fill(0, count($idsOrdenesPendientes), "?"));

            $consultaDetallesPendientes = $pdo->prepare(
                "SELECT
                    do.id_orden,
                    do.id_detalle,
                    do.id_producto,
                    do.id_presentacion,
                    do.cantidad,
                    do.precio_unitario,
                    do.subtotal,
                    p.nombre AS nombre_producto,
                    pg.nombre_presentacion
                 FROM detalle_orden do
                 LEFT JOIN productos p ON p.id_producto = do.id_producto
                 LEFT JOIN presentaciones_gramaje pg ON pg.id_presentacion = do.id_presentacion
                 WHERE do.id_orden IN ($marcadoresOrdenes)
                 ORDER BY do.id_orden ASC, do.id_detalle ASC"
            );
            $consultaDetallesPendientes->execute($idsOrdenesPendientes);

            foreach ($consultaDetallesPendientes->fetchAll(PDO::FETCH_ASSOC) as $detallePendiente) {
                $idOrdenDetalle = (int)$detallePendiente["id_orden"];
                if (!isset($detallesPendientes[$idOrdenDetalle])) {
                    $detallesPendientes[$idOrdenDetalle] = [];
                }
                $detallesPendientes[$idOrdenDetalle][] = $detallePendiente;
            }
        }
    } catch (PDOException $e) {
        error_log("Error al consultar órdenes pendientes de surtido: " . $e->getMessage());
    }
}


/* =====================================================
   12. MENÚ DE NAVEGACIÓN
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

            <?php if (tienePermiso("tienda")): ?>

                <p class="text-xs uppercase tracking-widest text-stone-500 mt-6 mb-3">
                    Tienda
                </p>

                <a href="tienda.php"
                   class="menu-link flex items-center gap-3 p-3 rounded-xl mb-1 text-amber-200 hover:text-white">

                    <i class="fa-solid fa-store w-5"></i>
                    <span class="text-sm">Abrir tienda en vivo</span>

                </a>

            <?php endif; ?>

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
                            Clientes
                        </p>

                        <h3 class="text-3xl font-bold text-stone-800 mt-2">
                            <?= $usuariosPorRol["cliente"] ?? 0 ?>
                        </h3>

                        <div class="mt-3 text-sky-700">
                            <i class="fa-solid fa-user-check text-xl"></i>
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
                                            <option value="supervisor">Supervisor (Nivel 3)</option>
                                            <option value="operador">Operador (Nivel 2)</option>

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
                                        ["admin", "supervisor", "operador", "cliente"]
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
                                            <option value="supervisor">Supervisor (Nivel 3)</option>
                                            <option value="operador">Operador (Nivel 2)</option>
                                            <option value="cliente">Cliente (Nivel 1)</option>
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
                            Registra productos y administra sus presentaciones y precios. Las existencias se controlan desde Inventario.
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
                                            Cada presentación tendrá su propio precio. Las existencias se administran desde Inventario.
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

                                        <div class="md:col-span-5">
                                            <p class="text-xs text-stone-500">
                                                La presentación iniciará con stock 0.00. Registra sus existencias desde el módulo Inventario.
                                            </p>
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
                                            Presentaciones y precios
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
                                        Aquí puedes modificar nombres, medidas y precios. Las existencias no se modifican desde Productos;
                                        se administran exclusivamente en el módulo Inventario. Las presentaciones existentes no se eliminan
                                        para conservar las referencias de pedidos anteriores.
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
                 MÓDULO DE SURTIDO
            ====================================== -->

<<<<<<< HEAD
=======
            <!-- =====================================
                 MÓDULO DE INVENTARIO
            ====================================== -->

>>>>>>> 6ede5fe4a49d6fe8667faa811b6bff0b79e43461
            <?php elseif ($moduloSeleccionado === "surtir" && tienePermiso("surtir")): ?>

                <div id="surtir">
                    <div class="mb-6">
                        <h3 class="text-2xl font-bold text-stone-800">Surtido de órdenes</h3>
                        <p class="text-stone-500 mt-1">
                            Revisa las órdenes pendientes, valida las existencias y confirma su surtido.
                        </p>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-7">
                        <div class="card p-5">
                            <p class="text-sm text-stone-500">Órdenes pendientes</p>
                            <p class="text-3xl font-bold text-stone-800 mt-2"><?= count($ordenesPendientes) ?></p>
                            <i class="fa-solid fa-clipboard-list text-amber-700 text-xl mt-3"></i>
                        </div>
                        <div class="card p-5">
                            <p class="text-sm text-stone-500">Líneas por preparar</p>
                            <p class="text-3xl font-bold text-stone-800 mt-2">
                                <?= array_sum(array_map(static fn($o) => (int)$o["total_lineas"], $ordenesPendientes)) ?>
                            </p>
                            <i class="fa-solid fa-boxes-stacked text-amber-700 text-xl mt-3"></i>
                        </div>
                        <div class="card p-5">
                            <p class="text-sm text-stone-500">Proceso</p>
                            <p class="text-lg font-bold text-stone-800 mt-3">Validación completa</p>
                            <i class="fa-solid fa-shield-halved text-emerald-700 text-xl mt-3"></i>
                        </div>
                    </div>

                    <?php if (count($ordenesPendientes) > 0): ?>
                        <div class="space-y-6">
                            <?php foreach ($ordenesPendientes as $ordenPendiente): ?>
                                <?php
                                    $idOrdenVista = (int)$ordenPendiente["id_orden"];
                                    $lineasOrden = $detallesPendientes[$idOrdenVista] ?? [];
                                    $ordenCompleta = count($lineasOrden) > 0;
                                    foreach ($lineasOrden as $lineaOrden) {
                                        if (empty($lineaOrden["id_presentacion"]) || empty($lineaOrden["nombre_presentacion"])) {
                                            $ordenCompleta = false;
                                        }
                                    }
                                ?>
                                <div class="card p-6">
                                    <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4 mb-5">
                                        <div>
                                            <div class="flex flex-wrap items-center gap-3">
                                                <h4 class="text-xl font-bold text-stone-800">Orden #<?= $idOrdenVista ?></h4>
                                                <span class="bg-amber-100 text-amber-900 px-3 py-1 rounded-full text-xs font-semibold">Pendiente</span>
                                            </div>
                                            <p class="text-sm text-stone-500 mt-2">
                                                Cliente ID: <?= (int)$ordenPendiente["id_cliente"] ?>
                                                <span class="mx-2">•</span>
                                                <?= escapar($ordenPendiente["fecha_creacion"]) ?>
                                            </p>
                                            <?php if (!empty($ordenPendiente["observaciones"])): ?>
                                                <p class="text-sm text-stone-600 mt-2">Observaciones: <?= escapar($ordenPendiente["observaciones"]) ?></p>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-left lg:text-right">
                                            <p class="text-xs text-stone-500">Total de la orden</p>
                                            <p class="text-2xl font-bold text-stone-800">$<?= number_format((float)$ordenPendiente["total_final"], 2) ?></p>
                                        </div>
                                    </div>

                                    <div class="table-container mb-5">
                                        <table class="w-full text-sm text-left">
                                            <thead class="bg-stone-100 text-stone-600">
                                                <tr>
                                                    <th class="p-3">Producto</th>
                                                    <th class="p-3">Presentación</th>
                                                    <th class="p-3">Cantidad</th>
                                                    <th class="p-3">Precio unitario</th>
                                                    <th class="p-3">Subtotal</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($lineasOrden as $lineaOrden): ?>
                                                    <tr class="border-b border-stone-100">
                                                        <td class="p-3 font-semibold"><?= escapar($lineaOrden["nombre_producto"] ?? "Producto no disponible") ?></td>
                                                        <td class="p-3"><?= escapar($lineaOrden["nombre_presentacion"] ?? "Presentación no disponible") ?></td>
                                                        <td class="p-3"><?= number_format((float)$lineaOrden["cantidad"], 2) ?></td>
                                                        <td class="p-3">$<?= number_format((float)$lineaOrden["precio_unitario"], 2) ?></td>
                                                        <td class="p-3">$<?= number_format((float)$lineaOrden["subtotal"], 2) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                <?php if (!$lineasOrden): ?>
                                                    <tr><td colspan="5" class="p-5 text-center text-red-700">Esta orden no tiene partidas registradas.</td></tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <?php if ($ordenCompleta): ?>
                                        <form method="POST" action="panel.php?modulo=surtir#surtir"
                                              onsubmit="return confirm('¿Confirmas el surtido de la orden #<?= $idOrdenVista ?>? Se descontarán las existencias del inventario.');"
                                              class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                                            <input type="hidden" name="csrf_token" value="<?= escapar($csrfToken) ?>">
                                            <input type="hidden" name="accion" value="confirmar_surtido">
                                            <input type="hidden" name="id_orden" value="<?= $idOrdenVista ?>">
                                            <p class="text-xs text-stone-500">El sistema verificará todas las existencias antes de aplicar cualquier descuento.</p>
                                            <button type="submit" class="btn-primary whitespace-nowrap">
                                                <i class="fa-solid fa-box-open mr-2"></i>Confirmar surtido
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <div class="bg-red-50 border border-red-200 text-red-800 rounded-xl p-4 text-sm">
                                            No se puede surtir esta orden: contiene partidas sin presentación válida o no tiene detalles.
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="card p-10 text-center">
                            <i class="fa-solid fa-clipboard-check text-4xl text-emerald-700 mb-4"></i>
                            <h4 class="text-xl font-bold text-stone-800">No hay órdenes pendientes</h4>
                            <p class="text-stone-500 mt-2">Cuando se registren órdenes con estado Pendiente, aparecerán aquí para su preparación.</p>
                        </div>
                    <?php endif; ?>
                </div>


<<<<<<< HEAD
            <!-- =====================================
                 MÓDULO DE INVENTARIO
            ====================================== -->

=======
>>>>>>> 6ede5fe4a49d6fe8667faa811b6bff0b79e43461
            <?php elseif ($moduloSeleccionado === "inventario" && tienePermiso("inventario")): ?>

                <div id="inventario">

                    <div class="mb-6">
                        <h3 class="text-2xl font-bold text-stone-800">
                            Control de inventario
                        </h3>
                        <p class="text-stone-500 mt-1">
                            Administra las existencias por presentación y consulta el historial de movimientos.
                        </p>
                    </div>

                    <?php
                        $totalPresentaciones = count($listaInventario);
                        $presentacionesSinStock = 0;
                        $unidadesTotales = 0;

                        foreach ($listaInventario as $filaInventario) {
                            $stockFila = (float)$filaInventario["stock"];
                            $unidadesTotales += $stockFila;
                            if ($stockFila <= 0) {
                                $presentacionesSinStock++;
                            }
                        }
                    ?>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-7">

                        <div class="card p-5">
                            <p class="text-sm text-stone-500">Presentaciones activas</p>
                            <p class="text-3xl font-bold text-stone-800 mt-2">
                                <?= $totalPresentaciones ?>
                            </p>
                            <i class="fa-solid fa-boxes-stacked text-amber-700 text-xl mt-3"></i>
                        </div>

                        <div class="card p-5">
                            <p class="text-sm text-stone-500">Presentaciones sin existencias</p>
                            <p class="text-3xl font-bold text-stone-800 mt-2">
                                <?= $presentacionesSinStock ?>
                            </p>
                            <i class="fa-solid fa-box-open text-red-700 text-xl mt-3"></i>
                        </div>

                        <div class="card p-5">
                            <p class="text-sm text-stone-500">Unidades totales registradas</p>
                            <p class="text-3xl font-bold text-stone-800 mt-2">
                                <?= number_format($unidadesTotales, 2) ?>
                            </p>
                            <i class="fa-solid fa-warehouse text-emerald-700 text-xl mt-3"></i>
                        </div>

                    </div>

                    <div class="card p-6 mb-7">

                        <h3 class="text-lg font-bold text-stone-800 mb-2">
                            <i class="fa-solid fa-arrows-rotate text-amber-700 mr-2"></i>
                            Registrar movimiento
                        </h3>

                        <p class="text-sm text-stone-500 mb-5">
                            Las cantidades de entrada y salida son movimientos. En Ajuste debes introducir el stock final real.
                        </p>

                        <?php if (count($listaInventario) > 0): ?>

                            <form method="POST" action="panel.php?modulo=inventario#inventario"
                                  class="grid grid-cols-1 md:grid-cols-2 gap-5">

                                <input type="hidden" name="csrf_token" value="<?= escapar($csrfToken) ?>">
                                <input type="hidden" name="accion" value="registrar_movimiento_inventario">

                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold mb-2">
                                        Presentación
                                    </label>
                                    <select name="id_presentacion" class="input-field" required>
                                        <option value="">Seleccionar producto y presentación</option>
                                        <?php foreach ($listaInventario as $itemInventario): ?>
                                            <option value="<?= (int)$itemInventario["id_presentacion"] ?>">
                                                <?= escapar($itemInventario["nombre_producto"]) ?>
                                                — <?= escapar($itemInventario["nombre_presentacion"]) ?>
                                                (Stock: <?= number_format((float)$itemInventario["stock"], 2) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-sm font-semibold mb-2">
                                        Tipo de movimiento
                                    </label>
                                    <select name="tipo_movimiento" id="tipoMovimientoInventario"
                                            class="input-field" required>
                                        <option value="Entrada">Entrada</option>
                                        <option value="Salida">Salida</option>
                                        <option value="Ajuste">Ajuste de existencias</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-sm font-semibold mb-2"
                                           id="etiquetaCantidadInventario">
                                        Cantidad
                                    </label>
                                    <input type="number" name="cantidad" class="input-field"
                                           min="0" step="0.01" required>
                                    <p class="text-xs text-stone-500 mt-1"
                                       id="ayudaCantidadInventario">
                                        Introduce la cantidad que ingresará o saldrá del inventario.
                                    </p>
                                </div>

                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold mb-2">
                                        Motivo / Observaciones
                                    </label>
                                    <input type="text" name="motivo" class="input-field"
                                           maxlength="255"
                                           placeholder="Ej. Compra a proveedor, merma, corrección de conteo...">
                                </div>

                                <div class="md:col-span-2">
                                    <button type="submit" class="btn-primary"
                                            onclick="return confirm('¿Confirmas el registro de este movimiento?');">
                                        <i class="fa-solid fa-floppy-disk mr-2"></i>
                                        Registrar movimiento
                                    </button>
                                </div>

                            </form>

                        <?php else: ?>

                            <div class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-amber-900">
                                No hay presentaciones activas disponibles. Primero registra un producto y sus presentaciones en el módulo Productos.
                            </div>

                        <?php endif; ?>

                    </div>

                    <div class="card p-6 mb-7">

                        <div class="flex flex-wrap justify-between items-center gap-3 mb-5">
                            <div>
                                <h3 class="font-bold text-stone-800">Existencias actuales</h3>
                                <p class="text-xs text-stone-500 mt-1">
                                    El stock se controla individualmente por presentación.
                                </p>
                            </div>
                            <input type="search" id="buscarInventario"
                                   class="input-field max-w-xs"
                                   placeholder="Buscar producto...">
                        </div>

                        <div class="table-container">
                            <table class="w-full text-sm text-left">
                                <thead class="bg-stone-100 text-stone-600">
                                    <tr>
                                        <th class="p-3">Producto</th>
                                        <th class="p-3">Categoría</th>
                                        <th class="p-3">Presentación</th>
                                        <th class="p-3">Precio</th>
                                        <th class="p-3">Existencias</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($listaInventario) > 0): ?>
                                        <?php foreach ($listaInventario as $itemInventario): ?>
                                            <tr class="border-b border-stone-100 fila-inventario"
                                                data-busqueda="<?= escapar(mb_strtolower(
                                                    $itemInventario["nombre_producto"] . " " .
                                                    ($itemInventario["categoria"] ?? "") . " " .
                                                    $itemInventario["nombre_presentacion"]
                                                )) ?>">
                                                <td class="p-3 font-semibold text-stone-800">
                                                    <?= escapar($itemInventario["nombre_producto"]) ?>
                                                </td>
                                                <td class="p-3">
                                                    <?= escapar($itemInventario["categoria"] ?? "Sin categoría") ?>
                                                </td>
                                                <td class="p-3">
                                                    <?= escapar($itemInventario["nombre_presentacion"]) ?>
                                                </td>
                                                <td class="p-3">
                                                    $<?= number_format((float)$itemInventario["precio"], 2) ?>
                                                </td>
                                                <td class="p-3 font-semibold">
                                                    <?= number_format((float)$itemInventario["stock"], 2) ?> unid.
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" class="p-6 text-center text-stone-500">
                                                No hay existencias para mostrar.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                    </div>

                    <div class="card p-6">

                        <div class="mb-5">
                            <h3 class="font-bold text-stone-800">Historial de movimientos</h3>
                            <p class="text-xs text-stone-500 mt-1">
                                Se muestran los últimos 100 movimientos registrados.
                            </p>
                        </div>

                        <div class="table-container">
                            <table class="w-full text-sm text-left">
                                <thead class="bg-stone-100 text-stone-600">
                                    <tr>
                                        <th class="p-3">Fecha</th>
                                        <th class="p-3">Producto / Presentación</th>
                                        <th class="p-3">Tipo</th>
                                        <th class="p-3">Cantidad</th>
                                        <th class="p-3">Stock anterior</th>
                                        <th class="p-3">Stock nuevo</th>
                                        <th class="p-3">Responsable</th>
                                        <th class="p-3">Motivo</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($historialInventario) > 0): ?>
                                        <?php foreach ($historialInventario as $movimiento): ?>
                                            <?php
                                                $claseTipo = [
                                                    "Entrada" => "bg-green-100 text-green-800",
                                                    "Salida" => "bg-red-100 text-red-800",
                                                    "Ajuste" => "bg-amber-100 text-amber-900",
                                                    "Surtido" => "bg-blue-100 text-blue-800"
                                                ][$movimiento["tipo"]] ?? "bg-stone-100 text-stone-700";
                                            ?>
                                            <tr class="border-b border-stone-100">
                                                <td class="p-3 whitespace-nowrap">
                                                    <?= escapar($movimiento["fecha_movimiento"]) ?>
                                                </td>
                                                <td class="p-3">
                                                    <div class="font-semibold">
                                                        <?= escapar($movimiento["nombre_producto"]) ?>
                                                    </div>
                                                    <div class="text-xs text-stone-500">
                                                        <?= escapar($movimiento["nombre_presentacion"]) ?>
                                                    </div>
                                                </td>
                                                <td class="p-3">
                                                    <span class="<?= $claseTipo ?> px-2 py-1 rounded-lg text-xs font-semibold">
                                                        <?= escapar($movimiento["tipo"]) ?>
                                                    </span>
                                                </td>
                                                <td class="p-3">
                                                    <?= number_format((float)$movimiento["cantidad"], 2) ?>
                                                </td>
                                                <td class="p-3">
                                                    <?= number_format((float)$movimiento["stock_anterior"], 2) ?>
                                                </td>
                                                <td class="p-3 font-semibold">
                                                    <?= number_format((float)$movimiento["stock_nuevo"], 2) ?>
                                                </td>
                                                <td class="p-3">
                                                    <?= escapar($movimiento["nombre_usuario"] ?? "Usuario no disponible") ?>
                                                </td>
                                                <td class="p-3">
                                                    <?= escapar($movimiento["motivo"] ?? "—") ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="8" class="p-6 text-center text-stone-500">
                                                Todavía no hay movimientos registrados.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                    </div>

                </div>


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
        "grid grid-cols-1 md:grid-cols-4 gap-3 p-4 rounded-xl bg-stone-50 border border-stone-200";

    const id = datos ? datos.id : 0;
    const nombre = datos ? datos.nombre : "";
    const valor = datos ? datos.valor : "";
    const unidad = datos ? datos.unidad : "g";
    const precio = datos ? datos.precio : "";

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

    });

    if (esEdicion && id === 0) {

        const boton = document.createElement("button");

        boton.type = "button";
        boton.className =
            "md:col-span-4 text-sm text-red-700 hover:text-red-900 text-left";

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
   FORMULARIO DE MOVIMIENTOS DE INVENTARIO
================================================ */

const tipoMovimientoInventario = document.getElementById("tipoMovimientoInventario");
const etiquetaCantidadInventario = document.getElementById("etiquetaCantidadInventario");
const ayudaCantidadInventario = document.getElementById("ayudaCantidadInventario");

function actualizarAyudaMovimientoInventario() {

    if (!tipoMovimientoInventario || !etiquetaCantidadInventario || !ayudaCantidadInventario) {
        return;
    }

    if (tipoMovimientoInventario.value === "Ajuste") {
        etiquetaCantidadInventario.textContent = "Stock final real";
        ayudaCantidadInventario.textContent =
            "Introduce la existencia total que contaste físicamente, no la diferencia.";
    } else if (tipoMovimientoInventario.value === "Salida") {
        etiquetaCantidadInventario.textContent = "Cantidad que saldrá";
        ayudaCantidadInventario.textContent =
            "Introduce la cantidad que se retirará. No puede superar el stock disponible.";
    } else {
        etiquetaCantidadInventario.textContent = "Cantidad que ingresará";
        ayudaCantidadInventario.textContent =
            "Introduce la cantidad que se agregará al inventario.";
    }
}

if (tipoMovimientoInventario) {
    tipoMovimientoInventario.addEventListener("change", actualizarAyudaMovimientoInventario);
    actualizarAyudaMovimientoInventario();
}

const buscadorInventario = document.getElementById("buscarInventario");

if (buscadorInventario) {

    buscadorInventario.addEventListener("input", function() {

        const texto = this.value.toLowerCase().trim();

        document.querySelectorAll(".fila-inventario").forEach(fila => {

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