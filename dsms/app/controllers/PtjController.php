<?php
/** PtjController - departments / units. Part of UC015 (account set-up needs PTJ). */
class PtjController extends Controller
{
    public function index(): void
    {
        $this->adminOnly();
        $model = new Ptj();
        $edit  = ($id = $this->getInt('edit')) ? $model->find($id) : null;
        $this->view('ptj/index', ['title' => 'Departments (PTJ)', 'rows' => $model->all(), 'edit' => $edit]);
    }

    public function save(): void
    {
        $this->adminOnly();
        $this->requirePost();
        $model = new Ptj();
        $id    = $this->postInt('id');
        $code  = strtoupper($this->post('code'));
        $name  = $this->post('name');

        if ($code === '' || $name === '') {
            $this->flashRedirect('danger', 'Code and name are both required.', 'ptj/index', $id ? ['edit' => $id] : []);
        }
        if ($model->codeTaken($code, $id)) {
            $this->flashRedirect('danger', "The code $code is already used by another department.", 'ptj/index', $id ? ['edit' => $id] : []);
        }
        $id ? $model->update($id, $code, $name) : $model->create($code, $name);
        $this->flashRedirect('success', 'Department saved.', 'ptj/index');
    }

    /** A department that still has users cannot be removed (their PTJ would be lost). */
    public function delete(): void
    {
        $this->adminOnly();
        $this->requirePost();
        $model = new Ptj();
        $id = $this->postInt('id');
        $row = array_values(array_filter($model->all(), fn($r) => (int)$r['id'] === $id))[0] ?? null;
        if (!$row) {
            $this->flashRedirect('danger', 'Department not found.', 'ptj/index');
        }
        if ((int)$row['user_count'] > 0) {
            $this->flashRedirect('warning', 'This department still has users, so it cannot be deleted.', 'ptj/index');
        }
        $model->delete($id);
        $this->flashRedirect('success', 'Department deleted.', 'ptj/index');
    }
}
