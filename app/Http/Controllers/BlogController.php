<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\BlogPosts;
use Illuminate\View\View;

final class BlogController extends Controller
{
    public function index(): View
    {
        $posts = BlogPosts::all();

        return view('static.blog.index', [
            'featuredPost' => $posts[0] ?? null,
            'posts' => array_slice($posts, 1),
            'allPosts' => $posts,
            'categories' => BlogPosts::categories(),
        ]);
    }

    public function show(string $slug): View
    {
        $post = BlogPosts::find($slug);

        abort_if($post === null, 404);

        return view('static.blog.show', [
            'post' => $post,
            'relatedPosts' => BlogPosts::related($slug),
        ]);
    }
}
