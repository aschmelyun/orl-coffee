<?php
session_start();

// Define constants for the whole app
define('APP_ENV', 'local');
define('APP_NAME', 'ORL Coffee');
define('APP_URL', 'http://localhost:8080');
define('DATABASE_HOST', 'mysql');
define('DATABASE_USER', 'coffee');
define('DATABASE_PASSWORD', 'secret');
define('DATABASE_NAME', 'coffeedb');
define('CACHE_PREFIX', 'orl_coffee_');
define('CACHE_TTL', 3600);

// Function to get cached data or fetch from database and cache it
function getCachedData($key, $callback)
{
    $cacheKey = CACHE_PREFIX . $key;
    if ($data = apcu_fetch($cacheKey)) {
        return $data;
    }

    $data = $callback();
    apcu_store($cacheKey, $data, CACHE_TTL);
    return $data;
}

// Check if user is logged in as admin
$is_admin = isset($_SESSION['admin_id']) && $_SESSION['admin_id'];

// Clear cache after successful create, update, or delete operations
if ($is_admin && $_SERVER['REQUEST_METHOD'] == 'POST') {
    apcu_clear_cache();
}

// Database connection
$db = mysqli_connect(DATABASE_HOST, DATABASE_USER, DATABASE_PASSWORD, DATABASE_NAME);
if (mysqli_connect_errno()) {
    die("Connection failed: " . mysqli_connect_error());
}

// If the app is in local and ?init=true is passed, initialize the admin user
if (APP_ENV === 'local' && isset($_GET['init']) && $_GET['init'] === 'true') {

    // Create a hashed password for the admin user
    $hashed_password = password_hash('secret', PASSWORD_BCRYPT);
    $query = "INSERT INTO admins (email, password) VALUES ('andrew@example.com', '$hashed_password')";
    mysqli_query($db, $query);

    die('Admin user created');
}

$allCoffeeShops = getCachedData('all_coffee_shops', function () use ($db) {
    return mysqli_fetch_all(mysqli_query($db, "SELECT * FROM shops"), MYSQLI_ASSOC);
});

$uniqueDrinkTypes = getCachedData('unique_drink_types', function () use ($allCoffeeShops) {
    return array_values(array_unique(
        array_merge(...array_map(function ($shop) {
            return array_map('trim', explode(',', $shop['drink_types']));
        }, $allCoffeeShops))
    ));
});

// Handle filtering form submission
$filters = [
    'food_available' => !empty($_GET['food_available']) ? $_GET['food_available'] : null,
    'drink_type' => !empty($_GET['drink_type']) ? $_GET['drink_type'] : null,
    'rating' => !empty($_GET['rating']) ? (int)$_GET['rating'] : null
];

// Function to get filtered coffee shops
function getFilteredCoffeeShops($filters)
{
    global $db;
    $cacheKey = 'filtered_shops_' . md5(serialize($filters));

    return getCachedData($cacheKey, function () use ($db, $filters) {
        $query = "SELECT * FROM shops WHERE 1=1";

        if (!empty($filters['rating'])) {
            $query .= " AND rating = '" . mysqli_real_escape_string($db, $filters['rating']) . "'";
        }
        if (!empty($filters['drink_type'])) {
            $query .= " AND drink_types LIKE '%" . mysqli_real_escape_string($db, $filters['drink_type']) . "%'";
        }
        if (!empty($filters['food_available'])) {
            $query .= " AND food_available = " . ($filters['food_available'] == 'yes' ? 1 : 0);
        }

        $result = mysqli_query($db, $query);
        return mysqli_fetch_all($result, MYSQLI_ASSOC);
    });
}

/**
 * REDIRECTS
 */

// Handle redirect if admin is logged in and the user is on the login page
if ($is_admin && $_SERVER['REQUEST_URI'] == '/login') {
    header('Location: /admin');
    exit;
}

// Handle redirect if admin is not logged in and user is on the admin page
if (!$is_admin && $_SERVER['REQUEST_URI'] == '/admin') {
    header('Location: /login');
    exit;
}

/**
 * DATABASE QUERIES
 */

// If at least one filter is set, get filtered coffee shops
$shops = array_filter($filters) ? getFilteredCoffeeShops($filters) : $allCoffeeShops;

// Handle detailed view
$shop_detail = null;
if (preg_match('/^\/shop\/([^\/]+)/', $_SERVER['REQUEST_URI'], $matches)) {
    $slug = mysqli_real_escape_string($db, $matches[1]);
    $shop_detail = getCachedData('shop_' . $slug, function () use ($db, $slug) {
        $result = mysqli_query($db, "SELECT s.*, GROUP_CONCAT(c.id, ':::', c.name, ':::', c.body SEPARATOR '|||') AS comments FROM shops s LEFT JOIN comments c ON s.id = c.shop_id WHERE slug = '$slug' GROUP BY s.id");
        return mysqli_fetch_assoc($result);
    });

    if ($shop_detail['comments']) {
        $shop_detail['comments'] = array_map(function ($comment) {
            list($id, $name, $body) = explode(':::', $comment);
            return compact('id', 'name', 'body');
        }, explode('|||', $shop_detail['comments']));
    } else {
        $shop_detail['comments'] = [];
    }
}

// Handle edit coffee shop form view using same variable
if ($is_admin && str_contains($_SERVER['REQUEST_URI'], '/admin/edit-coffee-shop')) {
    if (empty($_GET['id'])) {
        header('Location: /admin');
        exit;
    }

    $shop_detail = mysqli_fetch_assoc(mysqli_query($db, "SELECT * FROM shops WHERE id = " . $_GET['id']));

    if (!$shop_detail) {
        header('Location: /admin');
        exit;
    }
}

/**
 * FORM SUBMISSIONS
 */

// Function to authenticate user
function authenticateAdmin($email, $password)
{
    global $db;
    $email = mysqli_real_escape_string($db, $email);
    $query = "SELECT * FROM admins WHERE email = '$email'";
    $result = mysqli_query($db, $query);
    $admin = mysqli_fetch_assoc($result);

    if ($admin && password_verify($password, $admin['password'])) {
        return $admin;
    }

    return false;
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['login'])) {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    if ($admin = authenticateAdmin($email, $password)) {
        $_SESSION['admin_id'] = $admin['id'];
        header('Location: /admin');
        exit;
    } else {
        $login_error = "Invalid username or password";
    }
}

// Handle logout if admin is logged in
if ($is_admin && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['logout'])) {
    session_destroy();
    header('Location: /');
    exit;
}

// Handle create coffee shop form submission
if ($is_admin && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_coffee_shop'])) {
    $name = $_POST['name'] ?? '';
    $location = $_POST['location'] ?? '';
    $rating = $_POST['rating'] ?? '';
    $hours_open = $_POST['hours_open'] ?? '';
    $drink_types = $_POST['drink_types'] ?? '';
    $food_available = $_POST['food_available'] ?? 0;

    // Validate the form data
    if (empty($name) || empty($location) || empty($rating) || empty($hours_open) || empty($drink_types)) {
        $create_coffee_shop_error = "All fields are required";
        return;
    }

    // Create a slug for the coffee shop
    $slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)));

    // If an image is uploaded, save it to the images folder
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $image = uniqid() . '.' . pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
        move_uploaded_file($_FILES['image']['tmp_name'], 'images/' . $image);
    }

    // Insert the coffee shop into the database
    $query = "INSERT INTO shops (name, location, rating, hours_open, drink_types, food_available, slug, image) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = mysqli_prepare($db, $query);
    mysqli_stmt_bind_param($stmt, "ssississ", $name, $location, $rating, $hours_open, $drink_types, $food_available, $slug, $image);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    header('Location: /admin');
    exit;
}

// Handle update coffee shop form submission
if ($is_admin && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_coffee_shop'])) {
    $id = $_GET['id'] ?? '';
    $name = $_POST['name'] ?? '';
    $location = $_POST['location'] ?? '';
    $rating = $_POST['rating'] ?? '';
    $hours_open = $_POST['hours_open'] ?? '';
    $drink_types = $_POST['drink_types'] ?? '';
    $food_available = $_POST['food_available'] ?? 0;

    // Validate the form data
    if (empty($name) || empty($location) || empty($rating) || empty($hours_open) || empty($drink_types)) {
        $update_coffee_shop_error = "All fields are required";
        return;
    }

    // If an image is uploaded, save it to the images folder
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $image = uniqid() . '.' . pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
        move_uploaded_file($_FILES['image']['tmp_name'], 'images/' . $image);
    }

    // Update the slug based on the coffee shop name
    $slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)));

    // Update the coffee shop in the database
    $query = "UPDATE shops SET name = ?, location = ?, rating = ?, hours_open = ?, drink_types = ?, food_available = ?, slug = ?";
    if (isset($image)) {
        $query .= ", image = ?";
    }
    $query .= " WHERE id = ?";

    $stmt = mysqli_prepare($db, $query);
    $params = [$name, $location, $rating, $hours_open, $drink_types, $food_available, $slug];
    if (isset($image)) {
        $params[] = $image;
    }
    $params[] = $id;
    mysqli_stmt_bind_param($stmt, str_repeat("s", count($params)), ...$params);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    header('Location: /admin');
    exit;
}

// Handle delete coffee shop form submission
if ($is_admin && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_coffee_shop'])) {
    $id = $_GET['id'] ?? null;

    if (!$id) {
        header('Location: /admin');
        exit;
    }

    $query = "DELETE FROM shops WHERE id = ?";
    $stmt = mysqli_prepare($db, $query);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    header('Location: /admin');
    exit;
}

// Handle comment submitted to a particular coffee shop
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['comment_submitted'])) {
    $shop_id = $shop_detail['id'] ?? null;
    $name = $_POST['name'] ?? '';
    $body = $_POST['body'] ?? '';

    if (!$shop_id || empty($name) || empty($body)) {
        $comment_error = 'All fields are required to leave a comment.';
    }

    if ($shop_id && !empty($name) && !empty($body)) {
        $query = "INSERT INTO comments (shop_id, name, body) VALUES (?, ?, ?)";
        $stmt = mysqli_prepare($db, $query);
        mysqli_stmt_bind_param($stmt, "iss", $shop_id, $name, $body);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        // Clear the cache for this shop
        apcu_delete(CACHE_PREFIX . 'shop_' . $shop_detail['slug']);

        // Redirect to the same page to prevent form resubmission
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }
}

/**
 * MISC FUNCTIONS
 */

// Generate URL for shop detail
function getShopUrl($shop)
{
    return "/shop/" . urlencode($shop['slug']);
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Coffee Shop Finder</title>
    <link href="/styles.css" rel="stylesheet">
</head>

<body class="antialiased bg-gray-100">
    <!-- Hero Section -->
    <header class="bg-slate-100 py-8">
        <div class="container mx-auto px-4">
            <div class="flex items-center justify-between">
                <div>
                    <a href="/" class="inline-block text-4xl font-bold text-slate-900 mb-2">☕ <?php echo APP_NAME; ?></a>
                    <p class="text-lg text-slate-500 mb-6">Discover the best coffee in Orlando, Florida ☀️</p>
                </div>
                <div>
                    <span class="text-lg text-slate-500 mb-6">
                        <span class="font-bold"><?php echo count($shops); ?></span> coffee shops found
                    </span>
                </div>
            </div>
        </div>
    </header>

    <?php if (!$is_admin && $_SERVER['REQUEST_URI'] == '/login'): ?>
        <!-- Login Form -->
        <section class="py-12">
            <div class="container mx-auto px-4">
                <h2 class="text-2xl font-bold mb-4">Admin Login</h2>
                <?php if (isset($login_error)): ?>
                    <p class="text-red-500 mb-4"><?php echo $login_error; ?></p>
                <?php endif; ?>
                <form method="POST" action="/login">
                    <div class="mb-4">
                        <label for="email" class="block mb-2">Email</label>
                        <input type="email" id="email" name="email" required class="w-full p-2 border rounded">
                    </div>
                    <div class="mb-4">
                        <label for="password" class="block mb-2">Password</label>
                        <input type="password" id="password" name="password" required class="w-full p-2 border rounded">
                    </div>
                    <button type="submit" name="login" class="bg-indigo-500 hover:bg-indigo-600 text-white font-bold py-2 px-4 rounded">
                        Login
                    </button>
                </form>
            </div>
        </section>
    <?php elseif ($is_admin && $_SERVER['REQUEST_URI'] == '/admin'): ?>
        <!-- Admin Panel -->
        <section class="py-12">
            <div class="container mx-auto px-4">
                <h2 class="text-2xl font-bold mb-4">Admin Panel</h2>
                <p class="mb-4">Welcome to the admin panel. You can manage coffee shops here.</p>

                <!-- Add New Coffee Shop Button -->
                <a href="/admin/new-coffee-shop" class="bg-green-500 hover:bg-green-600 text-white font-bold py-2 px-4 rounded inline-block mb-6">
                    Add New Coffee Shop
                </a>

                <!-- Coffee Shops Table -->
                <table class="w-full bg-white shadow-md rounded mb-6">
                    <thead>
                        <tr class="bg-gray-200 text-gray-600 uppercase text-sm leading-normal">
                            <th class="py-3 px-6 text-left">Name</th>
                            <th class="py-3 px-6 text-left">Location</th>
                            <th class="py-3 px-6 text-center">Rating</th>
                            <th class="py-3 px-6 text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-600 text-sm font-light">
                        <?php
                        $result = mysqli_query($db, "SELECT * FROM shops");
                        while ($shop = mysqli_fetch_assoc($result)):
                        ?>
                            <tr class="border-b border-gray-200 hover:bg-gray-100">
                                <td class="py-3 px-6 text-left whitespace-nowrap">
                                    <?php echo htmlspecialchars($shop['name']); ?>
                                </td>
                                <td class="py-3 px-6 text-left">
                                    <?php echo htmlspecialchars($shop['location']); ?>
                                </td>
                                <td class="py-3 px-6 text-center">
                                    <?php echo htmlspecialchars($shop['rating']); ?>
                                </td>
                                <td class="py-3 px-6 text-center">
                                    <a href="/admin/edit-coffee-shop?id=<?php echo $shop['id']; ?>" class="bg-blue-500 hover:bg-blue-600 text-white font-bold py-1 px-3 rounded inline-block mr-2">
                                        Edit
                                    </a>
                                    <form method="POST" action="/admin/delete-coffee-shop?id=<?php echo $shop['id']; ?>" class="inline-block">
                                        <button type="submit" name="delete_coffee_shop" class="bg-red-500 hover:bg-red-600 text-white font-bold py-1 px-3 rounded" onclick="return confirm('Are you sure you want to delete this coffee shop?')">
                                            Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>

                <!-- Logout Form -->
                <form method="POST" action="/logout">
                    <button type="submit" name="logout" class="bg-red-500 hover:bg-red-600 text-white font-bold py-2 px-4 rounded inline-block mt-4">
                        Logout
                    </button>
                </form>
            </div>
        </section>
    <?php elseif ($is_admin && $_SERVER['REQUEST_URI'] == '/admin/new-coffee-shop'): ?>
        <!-- New Coffee Shop Form -->
        <section class="py-12">
            <div class="container mx-auto px-4">
                <h2 class="text-2xl font-bold mb-4">Add New Coffee Shop</h2>
                <?php if (isset($create_coffee_shop_error)): ?>
                    <p class="text-red-500 mb-4"><?php echo $create_coffee_shop_error; ?></p>
                <?php endif; ?>
                <form method="POST" action="/admin/new-coffee-shop" enctype="multipart/form-data">
                    <div class="mb-4">
                        <label for="name" class="block mb-2">Name</label>
                        <input type="text" id="name" name="name" required class="w-full p-2 border rounded">
                    </div>
                    <div class="mb-4">
                        <label for="location" class="block mb-2">Location</label>
                        <input type="text" id="location" name="location" required class="w-full p-2 border rounded">
                    </div>
                    <div class="mb-4">
                        <label for="rating" class="block mb-2">Rating</label>
                        <input type="number" id="rating" name="rating" required class="w-full p-2 border rounded">
                    </div>
                    <div class="mb-4">
                        <label for="hours_open" class="block mb-2">Hours Open</label>
                        <input type="text" id="hours_open" name="hours_open" required class="w-full p-2 border rounded">
                    </div>
                    <div class="mb-4">
                        <label for="drink_types" class="block mb-2">Drink Types</label>
                        <input type="text" id="drink_types" name="drink_types" required class="w-full p-2 border rounded">
                    </div>
                    <div class="mb-4">
                        <label for="food_available" class="block mb-2">Food Available</label>
                        <select id="food_available" name="food_available" required class="w-full p-2 border rounded">
                            <option value="1">Yes</option>
                            <option value="0">No</option>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label for="image" class="block mb-2">Image</label>
                        <input type="file" id="image" name="image" class="w-full p-2 border rounded">
                    </div>
                    <button type="submit" name="create_coffee_shop" class="bg-green-500 hover:bg-green-600 text-white font-bold py-2 px-4 rounded">
                        Add Coffee Shop
                    </button>
                </form>
            </div>
        </section>
    <?php elseif ($is_admin && str_contains($_SERVER['REQUEST_URI'], '/admin/edit-coffee-shop')): ?>
        <!-- Edit Coffee Shop Form -->
        <section class="py-12">
            <div class="container mx-auto px-4">
                <h2 class="text-2xl font-bold mb-4">Edit Coffee Shop</h2>
                <?php if (isset($update_coffee_shop_error)): ?>
                    <p class="text-red-500 mb-4"><?php echo $update_coffee_shop_error; ?></p>
                <?php endif; ?>
                <form method="POST" action="/admin/edit-coffee-shop?id=<?php echo $shop_detail['id']; ?>" enctype="multipart/form-data">
                    <div class="mb-4">
                        <label for="name" class="block mb-2">Name</label>
                        <input type="text" id="name" name="name" required value="<?php echo $shop_detail['name']; ?>" class="w-full p-2 border rounded">
                    </div>
                    <div class="mb-4">
                        <label for="location" class="block mb-2">Location</label>
                        <input type="text" id="location" name="location" value="<?php echo $shop_detail['location']; ?>" required class="w-full p-2 border rounded">
                    </div>
                    <div class="mb-4">
                        <label for="rating" class="block mb-2">Rating</label>
                        <input type="number" id="rating" name="rating" value="<?php echo $shop_detail['rating']; ?>" required class="w-full p-2 border rounded">
                    </div>
                    <div class="mb-4">
                        <label for="hours_open" class="block mb-2">Hours Open</label>
                        <input type="text" id="hours_open" name="hours_open" value="<?php echo $shop_detail['hours_open']; ?>" required class="w-full p-2 border rounded">
                    </div>
                    <div class="mb-4">
                        <label for="drink_types" class="block mb-2">Drink Types</label>
                        <input type="text" id="drink_types" name="drink_types" value="<?php echo $shop_detail['drink_types']; ?>" required class="w-full p-2 border rounded">
                    </div>
                    <div class="mb-4">
                        <label for="food_available" class="block mb-2">Food Available</label>
                        <select id="food_available" name="food_available" required class="w-full p-2 border rounded">
                            <option value="1" <?php echo $shop_detail['food_available'] ? 'selected' : ''; ?>>Yes</option>
                            <option value="0" <?php echo !$shop_detail['food_available'] ? 'selected' : ''; ?>>No</option>
                        </select>
                    </div>
                    <div class="mb-4 flex items-center gap-4">
                        <div class="flex items-center">
                            <label for="image" class="block mb-2">New Image</label>
                            <input type="file" id="image" name="image" class="w-full p-2 border rounded">
                        </div>
                        <div class="flex items-center">
                            <label for="image" class="block mb-2">Current Image</label>
                            <img src="/images/<?php echo $shop_detail['image']; ?>" alt="Current Image" class="w-full max-w-xs p-2 border rounded">
                        </div>
                    </div>
                    <button type="submit" name="update_coffee_shop" class="bg-blue-500 hover:bg-blue-600 text-white font-bold py-2 px-4 rounded">
                        Update Coffee Shop
                    </button>
                </form>
            </div>
        </section>
    <?php elseif ($shop_detail && str_contains($_SERVER['REQUEST_URI'], '/shop/')): ?>
        <!-- Coffee Shop Details -->
        <main class="container mx-auto py-8 px-4">
            <div class="bg-white rounded-lg shadow-lg overflow-hidden">
                <!-- Hero Image -->
                <div class="h-64 bg-cover bg-center" style="background-image: url('/images/<?php echo $shop_detail['image']; ?>');"></div>

                <!-- Coffee Shop Info -->
                <div class="p-6">
                    <div class="flex justify-between items-start mb-6">
                        <div>
                            <h1 class="text-3xl font-bold text-brown-800 mb-2"><?php echo $shop_detail['name']; ?></h1>
                            <div class="flex items-center">
                                <span class="text-yellow-500 text-xl mr-2"><?php echo str_repeat('★', $shop_detail['rating']) . str_repeat('☆', 5 - $shop_detail['rating']); ?></span>
                                <span class="text-gray-600">(<?php echo $shop_detail['rating']; ?> / 5 stars)</span>
                            </div>
                        </div>
                    </div>

                    <p class="text-gray-700 mb-4">A cozy spot for coffee enthusiasts, offering a wide range of artisanal coffees and delicious pastries in a warm, inviting atmosphere.</p>

                    <!-- Details Grid -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <div>
                            <h2 class="text-xl font-semibold mb-2 text-brown-700">Location</h2>
                            <p class="text-gray-600"><?php echo $shop_detail['location']; ?></p>
                        </div>
                        <div>
                            <h2 class="text-xl font-semibold mb-2 text-brown-700">Hours</h2>
                            <ul class="text-gray-600">
                                <?php foreach (explode("; ", $shop_detail['hours_open']) as $hours): ?>
                                    <li><?php echo $hours; ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>

                    <!-- Menu Preview -->
                    <div class="my-12">
                        <h2 class="text-2xl font-semibold mb-4 text-slate-900">Drinks and Menu Items</h2>
                        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                            <?php foreach (explode(",", $shop_detail['drink_types']) as $drink): ?>
                                <div class="border border-slate-200 p-4 rounded-lg">
                                    <h3 class="font-semibold"><?php echo trim($drink); ?></h3>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Comments Section -->
                     <?php if (!empty($shop_detail['comments'])): ?>
                        <div>
                            <h2 class="text-2xl font-semibold mb-4 text-slate-900">Recent Comments</h2>
                            <div class="space-y-4">
                                <?php foreach ($shop_detail['comments'] as $comment): ?>
                                    <div class="border border-slate-200 py-3 px-4 rounded-lg">
                                        <div class="font-semibold mb-2"><?php echo $comment['name']; ?></div>
                                        <p class="text-slate-600"><?php echo $comment['body']; ?></p>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Comment Form -->
                    <div class="mt-12">
                    <h2 class="text-2xl font-semibold mb-4 text-slate-900">Leave A Comment</h2>
                        <?php if (isset($comment_error)): ?>
                            <p class="text-red-500 mb-4"><?php echo $comment_error; ?></p>
                        <?php endif; ?>
                        <form method="POST" action="/shop/<?php echo $shop_detail['slug']; ?>/comment" class="space-y-4">
                            <div class="flex items-center gap-8 mb-4">
                                <div class="flex-1">
                                    <label for="name" class="block mb-2">Name</label>
                                    <input type="text" id="name" name="name" required class="w-full p-2 border rounded">
                                </div>
                                <div class="flex-1">
                                    <label for="body" class="block mb-2">Comment</label>
                                    <input type="text" id="body" name="body" required class="w-full p-2 border rounded">
                                </div>
                            </div>
                            <div>
                                <button type="submit" name="comment_submitted" class="bg-indigo-500 hover:bg-indigo-600 text-white font-bold py-2 px-4 rounded">
                                    Submit Comment
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </main>

    <?php else: ?>

        <!-- Filter Section -->
        <section class="bg-white py-8 shadow-lg border-t border-t-slate-200">
            <form method="GET" action="/" class="container mx-auto px-4">
                <div class="flex flex-wrap -mx-2">
                    <div class="w-full md:w-1/4 px-2 mb-4">
                        <select class="w-full p-2 border rounded" name="food_available">
                            <option value="">Food Available</option>
                            <option value="1" <?php echo $filters['food_available'] === '1' ? 'selected' : ''; ?>>Yes</option>
                            <option value="0" <?php echo $filters['food_available'] === '0' ? 'selected' : ''; ?>>No</option>
                        </select>
                    </div>
                    <div class="w-full md:w-1/4 px-2 mb-4">
                        <select class="w-full p-2 border rounded" name="drink_type">
                            <option value="">Drink Type</option>
                            <?php foreach ($uniqueDrinkTypes as $drinkType): ?>
                                <option value="<?php echo $drinkType; ?>" <?php echo $filters['drink_type'] === $drinkType ? 'selected' : ''; ?>><?php echo $drinkType; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="w-full md:w-1/4 px-2 mb-4">
                        <select class="w-full p-2 border rounded" name="rating">
                            <option value="">Rating</option>
                            <option value="1" <?php echo $filters['rating'] === 1 ? 'selected' : ''; ?>>1 Star</option>
                            <option value="2" <?php echo $filters['rating'] === 2 ? 'selected' : ''; ?>>2 Stars</option>
                            <option value="3" <?php echo $filters['rating'] === 3 ? 'selected' : ''; ?>>3 Stars</option>
                            <option value="4" <?php echo $filters['rating'] === 4 ? 'selected' : ''; ?>>4 Stars</option>
                            <option value="5" <?php echo $filters['rating'] === 5 ? 'selected' : ''; ?>>5 Stars</option>
                        </select>
                    </div>
                    <div class="w-full md:w-1/4 px-2 mb-4">
                        <input type="submit" value="Search" class="bg-indigo-500 hover:bg-indigo-600 text-white font-bold py-2 px-4 rounded hover:cursor-pointer">
                    </div>
                </div>
            </form>
        </section>

        <!-- Coffee Shop Grid -->
        <main class="py-12">
            <div class="container mx-auto px-4">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                    <?php foreach ($shops as $shop): ?>
                        <div class="bg-white rounded-lg shadow-md overflow-hidden">
                            <img src="/images/<?php echo $shop['image']; ?>" alt="<?php echo $shop['name']; ?> Image" class="w-full h-48 object-cover">
                            <div class="p-6">
                                <h2 class="text-xl font-semibold mb-2"><?php echo $shop['name']; ?></h2>
                                <p class="text-gray-600 mb-4"><?php echo $shop['location']; ?></p>
                                <div class="flex justify-between items-center">
                                    <span class="text-yellow-500"><?php echo str_repeat('★', $shop['rating']) . str_repeat('☆', 5 - $shop['rating']); ?></span>
                                    <a href="<?php echo getShopUrl($shop); ?>" class="block bg-indigo-500 hover:bg-indigo-600 text-white font-bold py-2 px-4 rounded">
                                        View Details
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </main>
    <?php endif; ?>
</body>

</html>