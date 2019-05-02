<?php

namespace App\Http\Controllers\Api;

use Auth;
use DB;
use Validator;
use App\Story;
use App\Category;
use App\Reaction;
use Illuminate\Http\Request;
use App\Services\FileUploadService;
use App\Http\Controllers\Controller;
use App\Http\Resources\StoryResource;

class StoryController extends Controller
{
    public function __construct(FileUploadService $fileUploadService)
    {
        $this->fileUploadService = $fileUploadService;
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $filter = $request->has("filter") ? $request->filter : 5;
        $stories =  StoryResource::collection(Story::whereRaw('? between age_from and age_to', [$filter])->get());
        // foreach ($stories as $key => $value) {
        //     # code...
        // }
        $user = $this->user();
        $storyId = [];
        for ($i = 0; $i < $stories->count(); $i++) {
            $storyId = $stories[$i]->id;
        }
        return $user;

        // return response()->json([
        //     'status' => 'success',
        //     'code' => 200,
        //     'message' => 'OK',
        //     'data' => $stories
        // ], 200);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required',
            'body' => 'required',
            'category_id' => 'required|numeric',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg',
            'age' => 'required',
            'author' => 'required',
            'story_duration' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => [
                    'code' => 422,
                    'message' => "Unprocessable Entity",
                    'errors' => $validator->errors()
                ]
            ], 422);
        }

        $category = Category::find($request->category_id);

        if (!$category) {
            return response()->json([
                'error' => 'Resource not found',
                'message' => 'Not found',
                'code' => 404
            ], 404);
        }

        DB::beginTransaction();

        if ($request->hasfile('photo')) {
            $image = $this->fileUploadService->uploadFile($request->file('photo'));
        }

        $story = Story::create([
            'title' => $request->title,
            'body' => $request->body,
            'category_id' => $request->category_id,
            'user_id' => auth()->id(),
            'age' => $request->age,
            'author' => $request->author,
            'story_duration' => $request->story_duration,
            "image_url" => $image['secure_url'] ?? null,
            "image_name" => $image['public_id'] ?? null
        ]);

        DB::commit();

        return response()->json([
            'status' => 'success',
            'code' => 200,
            'message' => 'OK',
            'data' => $story,
        ], 200);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $story = new StoryResource(Story::find($id));

        return response()->json([
            'status' => 'success',
            'data' => $story
        ], 200);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $if
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required',
            'body' => 'required',
            'category_id' => 'required|numeric',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg',
            'age' => 'required',
            'author' => 'required',
            'story_duration' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => [
                    'code' => 422,
                    'message' => "Unprocessable Entity",
                    'errors' => $validator->errors()
                ]
            ], 422);
        }

        $category = Category::find($request->category_id);

        if (!$category) {
            return response()->json([
                'error' => 'Category not found',
                'message' => 'Not found',
                'code' => 404
            ], 404);
        }

        $story = Story::where('id', $id)->where('user_id', auth()->id())->first();

        if (!$story) {
            return response()->json([
                'error' => 'Story not found',
                'message' => 'Not found',
                'code' => 404
            ], 404);
        }

        DB::beginTransaction();

        if ($request->hasfile('photo')) {
            $image = $this->fileUploadService->uploadFile($request->file('photo'));

            if (!is_null($story->image_name)) {
                $this->fileUploadService->deleteFile($story->image_name);
            }
        }

        $story->update([
            'title' => $request->title,
            'body' => $request->body,
            'category_id' => $request->category_id,
            'user_id' => auth()->id(),
            'age' => $request->age,
            'author' => $request->author,
            'story_duration' => $request->story_duration,
            "image_url" => $image['secure_url'] ?? $story->image_url,
            "image_name" => $image['public_id'] ?? $story->image_name
        ]);

        DB::commit();

        return response()->json([
            'status' => 'success',
            'code' => 200,
            'message' => 'OK'
        ], 200);
    }

    /**
     * User can like a story or remove like.
     *
     * @param  $id
     * @return \Illuminate\Http\Response
     */
    public function like($id)
    {
        $user = $this->user();

        $story = $this->findStory($id);

        $likeCount = $story['likes_count'];
        $dislikeCount = $story['dislikes_count'];

        $reaction = Reaction::where('story_id', $story->id)
            ->where('user_id', $user->id)
            ->first();

        DB::beginTransaction();

        if ($reaction && $reaction->reaction == 1) {
            $reaction->delete();
            $story->decrement('likes_count', 1);

            $likeCount = $story['likes_count'];
            $dislikeCount = $story['dislikes_count'];
        } else if ($reaction && $reaction->reaction == 0) {

            $story->increment('likes_count', 1);

            $story->decrement('dislikes_count', 1);

            $likeCount = $story['likes_count'];
            $dislikeCount = $story['dislikes_count'];

            $reaction = Reaction::updateOrCreate(
                ['story_id' => $id, 'user_id' => auth()->id()],
                ['reaction' => 1]
            );
        } else {
            $story->increment('likes_count', 1);

            $likeCount = $story['likes_count'];
            $dislikeCount = $story['dislikes_count'];

            $reaction = Reaction::updateOrCreate(
                ['story_id' => $id, 'user_id' => auth()->id()],
                ['reaction' => 1]
            );
        }

        DB::commit();

        return response()->json([
            'status' => 'success',
            'code' => 200,
            'message' => 'OK',
            'likes_count' => $likeCount,
            'dislikes_count' => $dislikeCount
        ], 200);
    }

    /**
     * User can dislike a story or remove dislike.
     *
     * @param  $id
     * @return \Illuminate\Http\Response
     */
    public function dislike($id)
    {
        $user = $this->user();

        $story = $this->findStory($id);

        $likeCount = $story['likes_count'];
        $dislikeCount = $story['dislikes_count'];

        $reaction = Reaction::where('story_id', $story->id)
            ->where('user_id', $user->id)
            ->first();

        DB::beginTransaction();

        if ($reaction && $reaction->reaction == 0) {
            $reaction->delete();
            $story->decrement('dislikes_count', 1);

            $likeCount = $story['likes_count'];
            $dislikeCount = $story['dislikes_count'];
        } else if ($reaction && $reaction->reaction == 1) {

            $story->increment('dislikes_count', 1);

            $story->decrement('likes_count', 1);

            $likeCount = $story['likes_count'];
            $dislikeCount = $story['dislikes_count'];

            $reaction = Reaction::updateOrCreate(
                ['story_id' => $id, 'user_id' => auth()->id()],
                ['reaction' => 0]
            );
        } else {
            $story->increment('dislikes_count', 1);

            $likeCount = $story['likes_count'];
            $dislikeCount = $story['dislikes_count'];

            $reaction = Reaction::updateOrCreate(
                ['story_id' => $id, 'user_id' => auth()->id()],
                ['reaction' => 0]
            );
        }

        DB::commit();

        return response()->json([
            'status' => 'success',
            'code' => 200,
            'message' => 'OK',
            'likes_count' => $likeCount,
            'dislikes_count' => $dislikeCount
        ], 200);
    }

    public function findStory($storyId)
    {
        $story = Story::find($storyId);
        if (!$story) {
            return response()->json([
                'status' => 'Not found',
                'code' => 404,
                'message' => "Story does not exist",
                'data' => null
            ], 404);
        } else {
            return $story;
        }
    }

    public function user()
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json([
                'status' => 'failed',
                'code' => 401,
                'message' => 'User unauthenticated',
                'data' => null
            ], 401);
        } else {
            return $user;
        }
    }

    /**
     * Like a story
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    /*public function likezzzzzz($id)
    {
        $reaction = Reaction::where('story_id', $id)
                            ->where('user_id', auth()->id())
                            ->first();

        if ($reaction && $reaction->reaction == 1) {
            $reaction->delete();
        } else {
            $reaction = Reaction::updateOrCreate([
                'story_id' => $id,
                'user_id' => auth()->id()
            ], [
                'reaction' => 1
            ]);
        }

        return response()->json([
            'status' => 'success',
            'code' => 200,
            'message' => 'OK'
        ], 200);
    }*/

    /**
     * Dislike a story
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    /*public function dislikezzzzzzz$id)
    {
        $reaction = Reaction::where('story_id', $id)
                            ->where('user_id', auth()->id())
                            ->first();

        if ($reaction && $reaction->reaction == 0) {
            $reaction->delete();

        } else {
            $reaction = Reaction::updateOrCreate([
                'story_id' => $id,
                'user_id' => auth()->id()
            ], [
                'reaction' => 0
            ]);
        }
        return response()->json([
            'status' => 'success',
            'code' => 200,
            'message' => 'OK'
        ], 200);
    }*/
}
