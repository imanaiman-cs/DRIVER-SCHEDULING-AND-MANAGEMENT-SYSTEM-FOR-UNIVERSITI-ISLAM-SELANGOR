<?php
/** CommentsController - UC005 Comment on a request. Add only: comments cannot be edited or deleted (R9). */
class CommentsController extends Controller
{
    public function add(): void
    {
        Auth::requireLogin();
        $this->requirePost();
        $id = $this->postInt('request_id');
        $model = new TripRequest();
        $request = $model->find($id);
        if (!$request) {
            http_response_code(404);
            View::render('errors/404', ['title' => 'Not found']);
            return;
        }
        $user = Auth::user();
        $legs = $model->legs($id);
        $driverUserIds = array_map('intval', array_filter(array_column($legs, 'driver_user_id')));
        if (!RequestPolicy::canView($user, $request, $driverUserIds)) {
            http_response_code(403);
            View::render('errors/403', ['title' => 'Access denied']);
            return;
        }

        $text = $this->post('comment');
        if ($text === '' || mb_strlen($text) > 1000) {
            $this->flashRedirect('danger', 'A comment must be between 1 and 1000 characters.', 'requests/show', ['id' => $id]);
        }
        (new RequestComment())->add($id, (int)$user['id'], $user['role'], $text);

        NotificationService::notify(
            array_diff($model->peopleOf($request), [(int)$user['id']]),
            "{$user['name']} commented on request #$id: " . mb_substr($text, 0, 120),
            page_link('requests/show', ['id' => $id])
        );
        $this->flashRedirect('success', 'Comment added.', 'requests/show', ['id' => $id]);
    }
}
