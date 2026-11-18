<?php
/** Admin: har program ki fee (tuition, admission, exam, other). Students isi program se link hote hain. */
require_once __DIR__ . '/../../includes/bootstrap.php';
$admin = require_login(['Admin']);

/** Form values validate karta hai; error ho to string return */
function read_fee_form(&$data)
{
    $data = ['program_name' => trim((string)($_POST['program_name'] ?? ''))];
    foreach (['tuition_fee', 'admission_fee', 'exam_fee', 'other_charges'] as $k) {
        $v = parse_amount($_POST[$k] ?? '0');
        if ($v === null) {
            return 'Fee amounts must be valid non-negative numbers.';
        }
        $data[$k] = $v;
    }
    if ($data['program_name'] === '' || strlen($data['program_name']) > 150) {
        return 'Please enter a valid program name.';
    }
    return null;
}

if (isset($_POST['add_fee']) || isset($_POST['update_fee'])) {
    $err = read_fee_form($d);
    $fee_id = (int)($_POST['fee_id'] ?? 0);
    if ($err) {
        flash('error', $err);
    } else {
        try {
            if (isset($_POST['add_fee'])) {
                db_exec("INSERT INTO fee_structure (program_name, tuition_fee, admission_fee, exam_fee, other_charges) VALUES (?, ?, ?, ?, ?)",
                    [$d['program_name'], $d['tuition_fee'], $d['admission_fee'], $d['exam_fee'], $d['other_charges']]);
                audit('FEE_STRUCTURE_ADDED', "Added fee structure for {$d['program_name']}");
                flash('success', 'Fee structure added successfully!');
            } else {
                db_exec("UPDATE fee_structure SET program_name = ?, tuition_fee = ?, admission_fee = ?, exam_fee = ?, other_charges = ? WHERE id = ?",
                    [$d['program_name'], $d['tuition_fee'], $d['admission_fee'], $d['exam_fee'], $d['other_charges'], $fee_id]);
                audit('FEE_STRUCTURE_UPDATED', "Updated fee structure for {$d['program_name']}");
                flash('success', 'Fee structure updated successfully!');
            }
        } catch (mysqli_sql_exception $ex) {
            if (!is_duplicate_error($ex)) { throw $ex; }
            flash('error', 'A fee structure for this program already exists.');
        }
    }
    redirect_self();
}

if (isset($_POST['delete_id'])) {
    $del = (int)$_POST['delete_id'];
    $prog = db_value("SELECT program_name FROM fee_structure WHERE id = ?", [$del]);
    $students = (int)db_value("SELECT COUNT(*) FROM students WHERE program_id = ?", [$del], 0);
    if (!$prog) {
        flash('error', 'Record not found.');
    } elseif ($students > 0) {
        flash('error', "Cannot delete \"{$prog}\": {$students} student(s) are enrolled in this program. Move them to another program first.");
    } else {
        db_exec("DELETE FROM fee_structure WHERE id = ?", [$del]);
        audit('FEE_STRUCTURE_DELETED', "Deleted fee structure for {$prog}");
        flash('success', 'Fee structure deleted successfully!');
    }
    redirect_self();
}

$search = trim((string)($_GET['search'] ?? ''));
$result = $search !== ''
    ? db_select("SELECT f.*, (SELECT COUNT(*) FROM students s WHERE s.program_id = f.id) AS student_count FROM fee_structure f WHERE program_name LIKE ? ORDER BY program_name", ["%{$search}%"])
    : db_select("SELECT f.*, (SELECT COUNT(*) FROM students s WHERE s.program_id = f.id) AS student_count FROM fee_structure f ORDER BY program_name");

$page_title = 'Fee Structure';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/admin_sidebar.php';

$fields = ['tuition_fee' => 'Tuition Fee', 'admission_fee' => 'Admission Fee', 'exam_fee' => 'Exam Fee', 'other_charges' => 'Other Charges'];
?>
<div class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <h4 class="fw-bold mb-0"><i class="fas fa-file-invoice-dollar me-2 text-primary"></i>Program Fee Structure</h4>
        <button class="btn btn-brand rounded-pill px-4" data-bs-toggle="modal" data-bs-target="#addFeeModal"><i class="fas fa-plus me-1"></i> Add Program</button>
    </div>
    <?php echo flash_render(); ?>

    <div class="card p-3 border-0 shadow-sm rounded-4 mb-4">
        <form method="GET" action="" class="row g-2">
            <div class="col-md-9"><input type="text" name="search" maxlength="100" class="form-control" placeholder="Search program..." value="<?php echo e($search); ?>"></div>
            <div class="col-md-3 d-flex gap-2">
                <button class="btn btn-primary w-100"><i class="fas fa-search me-1"></i> Search</button>
                <?php if ($search !== ''): ?><a href="?" class="btn btn-outline-secondary"><i class="fas fa-undo"></i></a><?php endif; ?>
            </div>
        </form>
    </div>

    <div class="card table-card overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 sticky-first">
                <thead class="table-light"><tr><th class="ps-4">Program</th><th>Tuition</th><th>Admission</th><th>Exam</th><th>Other</th><th>Semester Total*</th><th>Students</th><th class="text-center">Actions</th></tr></thead>
                <tbody>
                <?php if ($result->num_rows > 0): while ($row = $result->fetch_assoc()): $rid = (int)$row['id'];
                    $sem_total = $row['tuition_fee'] + $row['exam_fee'] + $row['other_charges']; ?>
                    <tr>
                        <td class="ps-4 fw-bold"><?php echo e($row['program_name']); ?></td>
                        <td><?php echo money($row['tuition_fee']); ?></td>
                        <td><?php echo money($row['admission_fee']); ?></td>
                        <td><?php echo money($row['exam_fee']); ?></td>
                        <td><?php echo money($row['other_charges']); ?></td>
                        <td class="fw-bold text-success">PKR&nbsp;<?php echo money($sem_total); ?></td>
                        <td><span class="badge bg-light text-dark border"><?php echo (int)$row['student_count']; ?></span></td>
                        <td class="text-center text-nowrap">
                            <button class="btn btn-sm btn-outline-primary rounded-circle me-1" data-bs-toggle="modal" data-bs-target="#editFee<?php echo $rid; ?>"><i class="fas fa-edit"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-danger rounded-circle" data-confirm-post data-title="Delete fee structure?" data-confirm="Delete"
                                    data-text="<?php echo e('Delete fee structure for "' . $row['program_name'] . '"?'); ?>"
                                    data-fields="<?php echo e(json_encode(['delete_id' => $rid])); ?>"><i class="fas fa-trash"></i></button>

                            <div class="modal fade text-start" id="editFee<?php echo $rid; ?>" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog modal-dialog-centered"><div class="modal-content rounded-4 border-0 shadow">
                                    <div class="modal-header border-0"><h5 class="modal-title fw-bold">Edit Fee Structure</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                                    <form action="" method="POST">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="fee_id" value="<?php echo $rid; ?>">
                                        <div class="modal-body">
                                            <div class="mb-3"><label class="form-label fw-semibold">Program Name <span class="req">*</span></label><input type="text" name="program_name" maxlength="150" class="form-control" value="<?php echo e($row['program_name']); ?>" required></div>
                                            <?php foreach ($fields as $k => $label): ?>
                                                <div class="mb-3"><label class="form-label fw-semibold"><?php echo e($label); ?> (PKR) <span class="req">*</span></label><input type="number" step="0.01" min="0" name="<?php echo $k; ?>" class="form-control" value="<?php echo e($row[$k]); ?>" required><?php if ($k === 'admission_fee'): ?><div class="form-text">Charged in the 1st semester only.</div><?php endif; ?></div>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="modal-footer border-0"><button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button><button type="submit" name="update_fee" class="btn btn-primary rounded-pill px-4">Save Changes</button></div>
                                    </form>
                                </div></div>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; else: ?>
                    <tr><td colspan="8" class="text-center py-5 text-muted">No fee structures found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <p class="small text-muted mt-2">* Semester total = Tuition + Exam + Other. Admission fee is added only in the 1st semester. Scholarships are deducted per student.</p>
</div>

<div class="modal fade" id="addFeeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content rounded-4 border-0 shadow">
        <div class="modal-header border-0"><h5 class="modal-title fw-bold"><i class="fas fa-plus-circle me-2 text-primary"></i>Add Program Fee Structure</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <form action="" method="POST">
            <?php echo csrf_field(); ?>
            <div class="modal-body">
                <div class="mb-3"><label class="form-label fw-semibold">Program Name <span class="req">*</span></label><input type="text" name="program_name" maxlength="150" class="form-control" placeholder="e.g. Software Engineering" required></div>
                <?php foreach ($fields as $k => $label): ?>
                    <div class="mb-3"><label class="form-label fw-semibold"><?php echo e($label); ?> (PKR) <span class="req">*</span></label><input type="number" step="0.01" min="0" name="<?php echo $k; ?>" class="form-control" value="0" required><?php if ($k === 'admission_fee'): ?><div class="form-text">Charged in the 1st semester only.</div><?php endif; ?></div>
                <?php endforeach; ?>
            </div>
            <div class="modal-footer border-0"><button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button><button type="submit" name="add_fee" class="btn btn-brand rounded-pill px-4">Save</button></div>
        </form>
    </div></div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
