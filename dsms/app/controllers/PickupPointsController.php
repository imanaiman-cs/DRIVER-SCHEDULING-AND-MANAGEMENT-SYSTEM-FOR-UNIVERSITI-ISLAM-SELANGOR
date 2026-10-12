<?php
/** PickupPointsController - the dropdown list used on the request form. */
class PickupPointsController extends Controller
{
    public function index(): void
    {
        $this->adminOnly();
        $model = new PickupPoint();
        $edit  = ($id = $this->getInt('edit')) ? $model->find($id) : null;
        $this->view('pickup_points/index', ['title' => 'Pickup points', 'rows' => $model->all(), 'edit' => $edit]);
    }

    public function save(): void
    {
        $this->adminOnly();
        $this->requirePost();
        $model = new PickupPoint();
        $id    = $this->postInt('id');
        $name  = $this->post('name');

        if ($name === '') {
            $this->flashRedirect('danger', 'The name is required.', 'pickup_points/index');
        }
        if ($model->nameTaken($name, $id)) {
            $this->flashRedirect('danger', 'This pickup point already exists.', 'pickup_points/index');
        }
        $id ? $model->rename($id, $name) : $model->create($name);
        $this->flashRedirect('success', 'Pickup point saved.', 'pickup_points/index');
    }

    public function toggle(): void
    {
        $this->adminOnly();
        $this->requirePost();
        $model = new PickupPoint();
        $row = $model->find($this->postInt('id'));
        if ($row) {
            $model->setActive((int)$row['id'], !$row['is_active']);
        }
        $this->flashRedirect('success', 'Pickup point updated.', 'pickup_points/index');
    }
}
