<div @class(['comment', 'is-reply' => $reply, 'is-note' => $comment->internal]) wire:key="comment-{{ $comment->id }}">
    <div class="comment-who">
        <b>{{ $comment->user->name }}</b>
        <span>{{ $comment->created_at->diffForHumans() }}</span>
        @if($comment->internal)<span class="chip wait">Internal</span>@endif
    </div>
    <p>{!! $this->mentions->render($comment->body) !!}</p>
    <div class="comment-acts">
        @can('comment', $task)
            <button type="button" class="link" wire:click="replyTo({{ $comment->id }})">Reply</button>
        @endcan
        @if($comment->user_id === auth()->id() || auth()->user()->can('delete', $task))
            <button type="button" class="link" wire:click="deleteComment({{ $comment->id }})" wire:confirm="Delete this comment and any replies?">Delete</button>
        @endif
    </div>
</div>
