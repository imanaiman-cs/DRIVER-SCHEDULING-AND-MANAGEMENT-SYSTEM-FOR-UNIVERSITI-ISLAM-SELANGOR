<?php
/**
 * HomeController - the role-based dashboard.
 * Every role sees "my requests" (every role is also staff) plus the cards
 * for the extra things that role can do.
 */
class HomeController extends Controller
{
    public function index(): void
    {
        Auth::requireLogin();
        $user   = Auth::user();
        $counts = (new Dashboard())->forUser($user);

        $this->view('dashboard/index', [
            'title'  => 'Dashboard',
            'user'   => $user,
            'counts' => $counts,
        ]);
    }
}
