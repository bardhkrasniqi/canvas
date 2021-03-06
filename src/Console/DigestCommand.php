<?php

namespace Canvas\Console;

use Canvas\Mail\WeeklyDigest;
use Canvas\Post;
use Canvas\UserMeta;
use Canvas\View;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Mail;

class DigestCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'canvas:digest';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send the weekly digest email';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        // Get all the users who have authored content
        $recipients = User::whereIn('id', Post::all()->pluck('user_id')->unique())->get();

        foreach ($recipients as $user) {

            // Verify that the user has enabled emails
            if (UserMeta::where('user_id', $user->id)->pluck('digest')->first()) {
                // Gather the post IDs for a given user
                $post_ids = Post::where('user_id', $user->id)->pluck('id');

                // Compile view count data for a user's posts
                $data = collect($this->compileViewData($post_ids->toArray(), 7));

                // Get the email of the user to notify
                $data->put('email', $user->email);

                // Get the weekly digest date ranges
                $data->put('start_date', now()->subDays(7)->format('M d'));
                $data->put('end_date', now()->format('M d, Y'));

                try {
                    Mail::send(new WeeklyDigest($data->toArray()));
                } catch (Exception $exception) {
                    logger()->error($exception->getMessage());
                }
            }
        }
    }

    /**
     * Return the view count data for posts given a number of days.
     *
     * @param array $post_ids
     * @param int $days
     * @return array
     */
    private function compileViewData(array $post_ids, int $days): array
    {
        $data = collect();
        $post_data = collect();

        foreach ($post_ids as $post_id) {

            // Get the post view count for a given number of days
            $post_views = View::whereBetween('created_at', [
                now()->subDays($days)->toDateTimeString(),
                now()->toDateTimeString(),
            ])->where('post_id', $post_id)->count();

            // Only collect view data if any is available
            if ($post_views) {
                $post = Post::find($post_id);
                $post_data->put($post->title, $post_views);
            }
        }

        $data->put('posts', $post_data);

        // Find the views belonging to a user for a given number of days
        $views = View::whereBetween('created_at', [
            now()->subDays($days)->toDateTimeString(),
            now()->toDateTimeString(),
        ])->whereIn('post_id', $post_ids)
            ->select('created_at')->get();

        $data->put('total_views', $views->count());

        return $data->toArray();
    }
}
