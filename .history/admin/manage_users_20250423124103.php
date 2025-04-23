<?php
include '../includes/auth.php';
include '../includes/functions.php';
include '../includes/db.php';

if (!($_SESSION['is_admin'] ?? false)) {
    header("Location: dashboard.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'update_role') {
        $user_id = $_POST['user_id'] ?? 0;
        $is_admin = isset($_POST['is_admin']) ? 1 : 0;
        
        $stmt = $pdo->prepare("UPDATE users SET is_admin = ? WHERE id = ?");
        $result = $stmt->execute([$is_admin, $user_id]);
        
        if ($result) {
            $success_message = "User role updated successfully!";
        } else {
            $error_message = "Failed to update user role.";
        }
    }
    
    if (isset($_POST['action']) && $_POST['action'] === 'delete_user') {
        $user_id = $_POST['user_id'] ?? 0;
        
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM users WHERE referred_by = ?");
        $stmt->execute([$user_id]);
        $has_referrals = $stmt->fetch()['count'] > 0;
        
        if ($has_referrals) {
            $error_message = "Cannot delete user with referrals. Remove referrals first.";
        } else {
            $stmt = $pdo->prepare("DELETE FROM contributions WHERE user_id = ?");
            $stmt->execute([$user_id]);
            
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $result = $stmt->execute([$user_id]);
            
            if ($result) {
                $success_message = "User deleted successfully!";
            } else {
                $error_message = "Failed to delete user.";
            }
        }
    }
    
    if (isset($_POST['action']) && $_POST['action'] === 'edit_user') {
        $user_id = $_POST['user_id'] ?? 0;
        $name = $_POST['name'] ?? '';
        $email = $_POST['email'] ?? '';
        
        if (empty($name) || empty($email)) {
            $error_message = "Name and email are required.";
        } else {
            $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?");
            $result = $stmt->execute([$name, $email, $user_id]);
            
            if ($result) {
                $success_message = "User information updated successfully!";
            } else {
                $error_message = "Failed to update user information.";
            }
        }
    }
}

$stmt = $pdo->query("SELECT users.*, COALESCE(SUM(contributions.amount), 0) as total_contributions,
                    (SELECT COUNT(*) FROM users as u WHERE u.referred_by = users.id) as referral_count
                    FROM users 
                    LEFT JOIN contributions ON users.id = contributions.user_id
                    GROUP BY users.id
                    ORDER BY users.name");
$users = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - Admin Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>

<body class="bg-gray-100 text-gray-800">
    <!-- Header -->
    <header class="bg-indigo-700 text-white p-4 flex justify-between items-center shadow fixed w-full z-10">
        <div class="flex items-center">
            <button id="sidebar-toggle" class="mr-3 text-white md:hidden">
                <i class="fas fa-bars text-xl"></i>
            </button>
            <h1 class="text-xl font-semibold">Admin Dashboard</h1>
        </div>
        <div class="flex items-center space-x-4">
            <span class="hidden md:inline">Welcome, <?php echo htmlspecialchars($_SESSION['name'] ?? 'Admin'); ?></span>
            <a href="../logout.php"
                class="bg-white text-indigo-700 px-4 py-2 rounded hover:bg-gray-100 transition">Logout</a>
        </div>
    </header>

    <div class="flex pt-16">
        <!-- Sidebar -->
        <aside id="sidebar"
            class="bg-indigo-800 text-white w-64 min-h-screen fixed z-10 transition-transform duration-300 ease-in-out md:translate-x-0 transform -translate-x-full">
            <div class="p-4">

                <nav>
                    <ul class="space-y-2">
                        <li>
                            <a href="dashboard.php" class="flex items-center p-2 rounded hover:bg-indigo-900">
                                <i class="fas fa-tachometer-alt mr-3"></i>
                                <span>Dashboard</span>
                            </a>
                        </li>
                        <li>
                            <a href="manage_users.php" class="flex items-center p-2 rounded bg-indigo-900">
                                <i class="fas fa-users mr-3"></i>
                                <span>Manage Users</span>
                            </a>
                        </li>
                        <li>
                            <a href="contribution_reports.php"
                                class="flex items-center p-2 rounded hover:bg-indigo-900">
                                <i class="fas fa-chart-line mr-3"></i>
                                <span>Contribution Reports</span>
                            </a>
                        </li>
                        <li>
                            <a href="referral_analytics.php" class="flex items-center p-2 rounded hover:bg-indigo-900">
                                <i class="fas fa-sitemap mr-3"></i>
                                <span>Referral Analytics</span>
                            </a>
                        </li>
                        <!--  <li class="pt-6 border-t border-indigo-700 mt-6">
                            <a href="settings.php" class="flex items-center p-2 rounded hover:bg-indigo-900">
                                <i class="fas fa-cog mr-3"></i>
                                <span>Settings</span>
                            </a>
                        </li> -->
                        <li class="pt-6 border-t border-indigo-700 mt-6">
                            <a href="../logout.php" class="flex items-center p-2 rounded hover:bg-indigo-900">
                                <i class="fas fa-sign-out-alt mr-3"></i>
                                <span>Logout</span>
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="flex-1 p-6 md:ml-64 transition-all duration-300 ease-in-out">
            <div class="flex flex-col md:flex-row md:justify-between md:items-center mb-6">
                <h2 class="text-2xl font-bold mb-4 md:mb-0">Manage Members</h2>
                <div class="flex flex-col md:flex-row gap-4">
                    <button id="addUserBtn"
                        class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700 transition flex items-center">
                        <i class="fas fa-plus mr-2"></i> Add New User
                    </button>
                    <div class="relative">
                        <input type="text" id="userSearch" placeholder="Search users..."
                            class="pl-10 pr-4 py-2 border rounded w-full">
                        <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
                    </div>
                </div>
            </div>

            <!-- Alerts -->
            <?php if (isset($success_message)): ?>
            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6" role="alert">
                <p><?php echo $success_message; ?></p>
            </div>
            <?php endif; ?>

            <?php if (isset($error_message)): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert">
                <p><?php echo $error_message; ?></p>
            </div>
            <?php endif; ?>

            <!-- Users Table -->
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200" id="usersTable">
                        <thead class="bg-indigo-600 text-white">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">ID</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Name</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Email</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Role</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">
                                    Contributions</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Referrals
                                </th>
                                <th class="px-6 py-3 text-center text-xs font-medium uppercase tracking-wider">Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($users as $user): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap"><?php echo $user['id']; ?></td>
                                <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($user['name']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($user['email']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span
                                        class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $user['is_admin'] ? 'bg-indigo-100 text-indigo-800' : 'bg-gray-100 text-gray-800'; ?>">
                                        <?php echo $user['is_admin'] ? 'Admin' : 'User'; ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-green-600 font-medium">
                                    $<?php echo number_format($user['total_contributions'], 2); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <?php echo $user['referral_count']; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <button class="text-indigo-600 hover:text-indigo-900 mx-1 editBtn"
                                        data-id="<?php echo $user['id']; ?>"
                                        data-name="<?php echo htmlspecialchars($user['name']); ?>"
                                        data-email="<?php echo htmlspecialchars($user['email']); ?>">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="text-blue-600 hover:text-blue-900 mx-1 roleBtn"
                                        data-id="<?php echo $user['id']; ?>"
                                        data-is-admin="<?php echo $user['is_admin']; ?>">
                                        <i class="fas fa-user-shield"></i>
                                    </button>
                                    <button class="text-red-600 hover:text-red-900 mx-1 deleteBtn"
                                        data-id="<?php echo $user['id']; ?>"
                                        data-name="<?php echo htmlspecialchars($user['name']); ?>">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <div id="addUserModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center z-50 hidden">
        <div class="bg-white rounded-lg shadow-lg p-6 w-full max-w-md">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-bold">Add New User</h3>
                <button class="text-gray-500 hover:text-gray-700 closeModal">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form action="process_add_user.php" method="POST">
                <div class="mb-4">
                    <label for="name" class="block text-gray-700 font-medium mb-2">Name</label>
                    <input type="text" id="name" name="name"
                        class="w-full px-4 py-2 border rounded focus:outline-none focus:ring-2 focus:ring-indigo-500"
                        required>
                </div>
                <div class="mb-4">
                    <label for="email" class="block text-gray-700 font-medium mb-2">Email</label>
                    <input type="email" id="email" name="email"
                        class="w-full px-4 py-2 border rounded focus:outline-none focus:ring-2 focus:ring-indigo-500"
                        required>
                </div>
                <div class="mb-4">
                    <label for="password" class="block text-gray-700 font-medium mb-2">Password</label>
                    <input type="password" id="password" name="password"
                        class="w-full px-4 py-2 border rounded focus:outline-none focus:ring-2 focus:ring-indigo-500"
                        required>
                </div>
                <div class="mb-4">
                    <label class="flex items-center">
                        <input type="checkbox" name="is_admin" class="mr-2">
                        <span>Admin Access</span>
                    </label>
                </div>
                <div class="flex justify-end">
                    <button type="button"
                        class="bg-gray-300 text-gray-800 px-4 py-2 rounded mr-2 closeModal">Cancel</button>
                    <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700">Add
                        User</button>
                </div>
            </form>
        </div>
    </div>

    <div id="editUserModal"
        class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center z-50 hidden">
        <div class="bg-white rounded-lg shadow-lg p-6 w-full max-w-md">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-bold">Edit User</h3>
                <button class="text-gray-500 hover:text-gray-700 closeModal">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" name="user_id" id="edit_user_id">
                <div class="mb-4">
                    <label for="edit_name" class="block text-gray-700 font-medium mb-2">Name</label>
                    <input type="text" id="edit_name" name="name"
                        class="w-full px-4 py-2 border rounded focus:outline-none focus:ring-2 focus:ring-indigo-500"
                        required>
                </div>
                <div class="mb-4">
                    <label for="edit_email" class="block text-gray-700 font-medium mb-2">Email</label>
                    <input type="email" id="edit_email" name="email"
                        class="w-full px-4 py-2 border rounded focus:outline-none focus:ring-2 focus:ring-indigo-500"
                        required>
                </div>
                <div class="flex justify-end">
                    <button type="button"
                        class="bg-gray-300 text-gray-800 px-4 py-2 rounded mr-2 closeModal">Cancel</button>
                    <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700">Update
                        User</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Change Role Modal -->
    <div id="roleUserModal"
        class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center z-50 hidden">
        <div class="bg-white rounded-lg shadow-lg p-6 w-full max-w-md">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-bold">Change User Role</h3>
                <button class="text-gray-500 hover:text-gray-700 closeModal">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_role">
                <input type="hidden" name="user_id" id="role_user_id">
                <div class="mb-4">
                    <label class="flex items-center">
                        <input type="checkbox" name="is_admin" id="is_admin_checkbox" class="mr-2">
                        <span>Admin Access</span>
                    </label>
                    <p class="text-sm text-gray-500 mt-2">
                        Admins have full access to manage users, view reports, and modify system settings.
                    </p>
                </div>
                <div class="flex justify-end">
                    <button type="button"
                        class="bg-gray-300 text-gray-800 px-4 py-2 rounded mr-2 closeModal">Cancel</button>
                    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Update
                        Role</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete User Modal -->
    <div id="deleteUserModal"
        class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center z-50 hidden">
        <div class="bg-white rounded-lg shadow-lg p-6 w-full max-w-md">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-bold">Delete User</h3>
                <button class="text-gray-500 hover:text-gray-700 closeModal">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <p class="mb-4">Are you sure you want to delete <span id="delete_user_name" class="font-semibold"></span>?
                This action cannot be undone.</p>
            <form method="POST">
                <input type="hidden" name="action" value="delete_user">
                <input type="hidden" name="user_id" id="delete_user_id">
                <div class="flex justify-end">
                    <button type="button"
                        class="bg-gray-300 text-gray-800 px-4 py-2 rounded mr-2 closeModal">Cancel</button>
                    <button type="submit" class="bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700">Delete
                        User</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Footer -->
    <footer class="bg-indigo-700 text-white text-center py-4 mt-8">
        <p>&copy; 2025 Church - Admin Panel</p>
    </footer>

    <script>
    const sidebarToggle = document.getElementById('sidebar-toggle');
    const sidebar = document.getElementById('sidebar');
    const main = document.querySelector('main');

    sidebarToggle.addEventListener('click', () => {
        sidebar.classList.toggle('-translate-x-full');
        main.classList.toggle('md:ml-0');
    });

    window.addEventListener('resize', () => {
        if (window.innerWidth >= 768) {
            main.classList.add('md:ml-64');
            sidebar.classList.add('md:translate-x-0');
        } else {
            sidebar.classList.add('-translate-x-full');
            main.classList.remove('md:ml-0');
        }
    });

    const addUserBtn = document.getElementById('addUserBtn');
    const addUserModal = document.getElementById('addUserModal');

    const editUserModal = document.getElementById('editUserModal');
    const editBtns = document.querySelectorAll('.editBtn');

    const roleUserModal = document.getElementById('roleUserModal');
    const roleBtns = document.querySelectorAll('.roleBtn');

    const deleteUserModal = document.getElementById('deleteUserModal');
    const deleteBtns = document.querySelectorAll('.deleteBtn');

    const closeModalBtns = document.querySelectorAll('.closeModal');

    addUserBtn.addEventListener('click', () => {
        addUserModal.classList.remove('hidden');
    });

    editBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            const userId = btn.dataset.id;
            const userName = btn.dataset.name;
            const userEmail = btn.dataset.email;

            document.getElementById('edit_user_id').value = userId;
            document.getElementById('edit_name').value = userName;
            document.getElementById('edit_email').value = userEmail;

            editUserModal.classList.remove('hidden');
        });
    });

    roleBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            const userId = btn.dataset.id;
            const isAdmin = btn.dataset.isAdmin === '1';

            document.getElementById('role_user_id').value = userId;
            document.getElementById('is_admin_checkbox').checked = isAdmin;

            roleUserModal.classList.remove('hidden');
        });
    });

    deleteBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            const userId = btn.dataset.id;
            const userName = btn.dataset.name;

            document.getElementById('delete_user_id').value = userId;
            document.getElementById('delete_user_name').textContent = userName;

            deleteUserModal.classList.remove('hidden');
        });
    });

    closeModalBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            addUserModal.classList.add('hidden');
            editUserModal.classList.add('hidden');
            roleUserModal.classList.add('hidden');
            deleteUserModal.classList.add('hidden');
        });
    });

    const userSearch = document.getElementById('userSearch');
    const usersTable = document.getElementById('usersTable');

    userSearch.addEventListener('keyup', function() {
        const searchText = this.value.toLowerCase();
        const rows = usersTable.querySelectorAll('tbody tr');

        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(searchText) ? '' : 'none';
        });
    });
    </script>
</body>

</html>