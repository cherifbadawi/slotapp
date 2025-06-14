<?php
/**
 * Edit Machine Group
 */

// Check if an ID was provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: index.php?page=machine_groups");
    exit;
}

$group_id = $_GET['id'];
$error = '';
$success = false;

// Get current group data
try {
    $stmt = $conn->prepare("SELECT * FROM machine_groups WHERE id = ?");
    $stmt->execute([$group_id]);
    $group = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$group) {
        header("Location: index.php?page=machine_groups&error=Group not found");
        exit;
    }
    
    // Get current group members
    $stmt = $conn->prepare("SELECT machine_id FROM machine_group_members WHERE group_id = ?");
    $stmt->execute([$group_id]);
    $current_machine_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
} catch (PDOException $e) {
    header("Location: index.php?page=machine_groups&error=Database error");
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = sanitize_input($_POST['name'] ?? '');
    $description = sanitize_input($_POST['description'] ?? '');
    $machine_ids = $_POST['machine_ids'] ?? [];

    // Validate required fields
    if (empty($name)) {
        $error = "Group name is required.";
    } elseif (count($machine_ids) < 2) {
        $error = "A group must contain at least 2 machines.";
    } else {
        try {
            // Check for duplicate name (excluding current group)
            $stmt = $conn->prepare("SELECT id FROM machine_groups WHERE name = ? AND id != ?");
            $stmt->execute([$name, $group_id]);
            if ($stmt->rowCount() > 0) {
                $error = "A group with this name already exists.";
            } else {
                // Start transaction
                $conn->beginTransaction();
                
                // Update group
                $stmt = $conn->prepare("
                    UPDATE machine_groups 
                    SET name = ?, description = ?
                    WHERE id = ?
                ");
                $stmt->execute([$name, $description ?: null, $group_id]);
                
                // Delete existing members
                $stmt = $conn->prepare("DELETE FROM machine_group_members WHERE group_id = ?");
                $stmt->execute([$group_id]);
                
                // Insert new members
                $stmt = $conn->prepare("INSERT INTO machine_group_members (group_id, machine_id) VALUES (?, ?)");
                foreach ($machine_ids as $machine_id) {
                    $stmt->execute([$group_id, $machine_id]);
                }
                
                // Commit transaction
                $conn->commit();

                log_action('update_machine_group', "Updated machine group: {$name} with " . count($machine_ids) . " machines");
                header("Location: index.php?page=machine_groups&message=Machine group updated successfully");
                exit;
            }
        } catch (PDOException $e) {
            // Rollback transaction on error
            $conn->rollback();
            $error = "Database error: " . $e->getMessage();
        }
    }
} else {
    // Pre-populate form with current data
    $name = $group['name'];
    $description = $group['description'];
    $machine_ids = $current_machine_ids;
}

// Get all machines for selection
try {
    $stmt = $conn->query("
        SELECT m.id, m.machine_number, b.name as brand_name, mt.name as type_name
        FROM machines m
        LEFT JOIN brands b ON m.brand_id = b.id
        LEFT JOIN machine_types mt ON m.type_id = mt.id
        ORDER BY m.machine_number
    ");
    $machines = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
    $machines = [];
}
?>

<div class="machine-group-edit fade-in">
    <div class="card">
        <div class="card-header">
            <h3>Edit Machine Group</h3>
        </div>
        <div class="card-body">
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" class="group-form" onsubmit="return validateGroupForm(this)">
                <div class="form-group">
                    <label for="name">Group Name *</label>
                    <input type="text" id="name" name="name" class="form-control" value="<?php echo htmlspecialchars($name); ?>" required>
                </div>

                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" class="form-control" rows="3"><?php echo htmlspecialchars($description); ?></textarea>
                </div>

                <div class="form-group">
                    <label>Select Machines * (minimum 2 required)</label>
                    <div class="machine-selection">
                        <?php if (empty($machines)): ?>
                            <p class="text-muted">No machines available</p>
                        <?php else: ?>
                            <div class="machine-grid">
                                <?php foreach ($machines as $machine): ?>
                                    <label class="machine-checkbox">
                                        <input type="checkbox" name="machine_ids[]" value="<?php echo $machine['id']; ?>" 
                                               <?php echo in_array($machine['id'], $machine_ids) ? 'checked' : ''; ?>>
                                        <div class="machine-info">
                                            <strong><?php echo htmlspecialchars($machine['machine_number']); ?></strong>
                                            <br>
                                            <small><?php echo htmlspecialchars($machine['brand_name'] ?? 'No Brand'); ?> - <?php echo htmlspecialchars($machine['type_name'] ?? 'No Type'); ?></small>
                                        </div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div id="selection-count" class="selection-info">
                        Selected: <span id="count">0</span> machines
                    </div>
                </div>

                <div class="form-group">
                    <button type="submit" class="btn btn-primary">Update Group</button>
                    <a href="index.php?page=machine_groups" class="btn btn-danger">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.machine-selection {
    border: 1px solid var(--border-color);
    border-radius: var(--border-radius);
    padding: var(--spacing-md);
    max-height: 400px;
    overflow-y: auto;
}

.machine-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: var(--spacing-sm);
}

.machine-checkbox {
    display: flex;
    align-items: center;
    padding: var(--spacing-sm);
    border: 1px solid var(--border-color);
    border-radius: var(--border-radius);
    cursor: pointer;
    transition: all var(--transition-speed);
}

.machine-checkbox:hover {
    background-color: rgba(255, 255, 255, 0.05);
}

.machine-checkbox input[type="checkbox"] {
    margin-right: var(--spacing-sm);
}

.machine-info {
    flex: 1;
}

.selection-info {
    margin-top: var(--spacing-sm);
    padding: var(--spacing-xs) var(--spacing-sm);
    background-color: rgba(255, 255, 255, 0.05);
    border-radius: var(--border-radius);
    font-size: var(--font-sm);
}

.selection-info #count {
    font-weight: bold;
    color: var(--secondary-color);
}
</style>

<script>
function validateGroupForm(form) {
    const checkboxes = form.querySelectorAll('input[name="machine_ids[]"]:checked');
    if (checkboxes.length < 2) {
        alert('Please select at least 2 machines for the group.');
        return false;
    }
    return true;
}

// Update selection count
document.addEventListener('DOMContentLoaded', function() {
    const checkboxes = document.querySelectorAll('input[name="machine_ids[]"]');
    const countElement = document.getElementById('count');
    
    function updateCount() {
        const checked = document.querySelectorAll('input[name="machine_ids[]"]:checked');
        countElement.textContent = checked.length;
        
        // Change color based on count
        if (checked.length < 2) {
            countElement.style.color = 'var(--danger-color)';
        } else {
            countElement.style.color = 'var(--success-color)';
        }
    }
    
    checkboxes.forEach(checkbox => {
        checkbox.addEventListener('change', updateCount);
    });
    
    // Initial count
    updateCount();
});
</script>