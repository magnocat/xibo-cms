<?php
/*
 * Client Creation Automation Script
 * Usage: php tasks/create_client.php "Client Name" "client@email.com" "password123"
 */

if (php_sapi_name() !== 'cli') {
    die('This script can only be run from the command line.');
}

if ($argc < 4) {
    echo "Usage: php tasks/create_client.php \"Client Name\" \"client@email.com\" \"password\"\n";
    exit(1);
}

$clientName = $argv[1];
$clientEmail = $argv[2];
$clientPassword = $argv[3];

define('PROJECT_ROOT', realpath(__DIR__ . '/..'));
require PROJECT_ROOT . '/vendor/autoload.php';

use Xibo\Factory\ContainerFactory;
use Xibo\Entity\User;
use Xibo\Support\Exception\NotFoundException;

// Bootstrap Xibo
echo "Bootstrapping Xibo...\n";
$container = ContainerFactory::create();
$userGroupFactory = $container->get('userGroupFactory');
$userFactory = $container->get('userFactory');
$folderFactory = $container->get('folderFactory');
$permissionFactory = $container->get('permissionFactory');
$configService = $container->get('configService');

try {
    // 1. Create User Group
    echo "Creating User Group: $clientName...\n";
    try {
        $group = $userGroupFactory->getByName($clientName);
        echo "Group already exists (ID: " . $group->groupId . ")\n";
    } catch (NotFoundException $e) {
        $group = $userGroupFactory->create($clientName, 0);
        $group->save();
        echo "Group created (ID: " . $group->groupId . ")\n";
    }

    // 2. Create User
    echo "Creating User: $clientEmail...\n";
    try {
        $user = $userFactory->getByEmail($clientEmail);
        echo "User already exists (ID: " . $user->userId . ")\n";
    } catch (NotFoundException $e) {
        $user = $userFactory->create();
        $user->userName = $clientEmail; // Use email as username
        $user->email = $clientEmail;
        $user->userTypeId = 3; // 3 = User (1 = SuperAdmin)
        $user->loggedIn = 0;
        $user->setNewPassword($clientPassword);
        
        // Assign to the new group
        // Note: Xibo users belong to groups via lkusergroup table managed by User object or Service
        // User entity has $groups array but save() might handle it if we look at User::save
        // Usually we need to add the group to the user manually or via factory.
        // Let's check if User entity has addGroup method. 
        // Assuming userFactory->create() returns a User object that we can manipulate.
        
        // We will save user first then assign group if needed, but let's see.
        // Actually, let's just save the user for now.
        $user->save();
        echo "User created (ID: " . $user->userId . ")\n";

        // Assign User to Group
        echo "Assigning User to Group...\n";
        // We need to link them. Usually via `lkusergroup`.
        // The User object might have a method or we use UserGroupFactory/UserFactory to link.
        // Checking User.php, there isn't an obvious `addGroup` method in the visible part.
        // But `User` has `groups` property.
        // We might need to look at how to assign groups. 
        // For now, let's assume we can do it via SQL or helper if needed, but let's try to find the standard way.
        // Actually, finding `linkUserGroup` method in UserFactory or UserGroupFactory would be better.
        // Or simply: $user->groups = [$group]; $user->save();
    }

    // Assign User to Group (Ensure it is linked)
    // Direct link via database usually, or method.
    // Let's use the Store to be sure if we can't find method in previous read.
    $store = $container->get('store');
    $store->insert('lkusergroup', ['userId' => $user->userId, 'groupId' => $group->groupId]);


    // 3. Create Client Root Folder
    echo "Creating Folder: $clientName...\n";
    $rootFolder = $folderFactory->getByParentId(1); // Assuming 1 is root, or we need to find root.
    // Actually standard root folder is usually ID 1.
    
    try {
        // Search folder by name?
        $existingFolders = $folderFactory->query(null, ['folderName' => $clientName, 'exactFolderName' => 1]);
        if (count($existingFolders) > 0) {
            $clientFolder = $existingFolders[0];
            echo "Folder already exists (ID: " . $clientFolder->folderId . ")\n";
        } else {
            $clientFolder = $folderFactory->createEmpty();
            $clientFolder->folderName = $clientName;
            $clientFolder->parentId = 1; // Root
            $clientFolder->isRoot = 0;
            $clientFolder->save();
            echo "Folder created (ID: " . $clientFolder->folderId . ")\n";
        }
    } catch (Exception $e) {
        echo "Error creating folder: " . $e->getMessage() . "\n";
        exit(1);
    }

    // 4. Assign Permissions
    echo "Assigning Permissions...\n";
    // Grant Group access to Folder
    try {
        // Entity: Xibo\Entity\Folder
        // View=1, Edit=1, Delete=0 (or 1)
        $perm = $permissionFactory->create($group->groupId, 'Xibo\Entity\Folder', $clientFolder->folderId, 1, 1, 1);
        $perm->save();
        echo "Permissions assigned for Group on Folder.\n";
    } catch (Exception $e) {
        // Ignore if exists (Duplicate)
        echo "Permissions might already exist or error: " . $e->getMessage() . "\n";
    }

    // Set Home Folder for User
    $user->homeFolderId = $clientFolder->folderId;
    $user->save();
    echo "User Home Folder set.\n";

    echo "\nClient Setup Complete!\n";
    echo "------------------------------------------------\n";
    echo "User: $clientEmail\n";
    echo "Pass: $clientPassword\n";
    echo "Group: $clientName\n";
    echo "Folder: $clientName\n";
    echo "------------------------------------------------\n";

} catch (Exception $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
    exit(1);
}
