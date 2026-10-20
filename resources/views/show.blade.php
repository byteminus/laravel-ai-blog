@extends(config('ai-blog.routes.layout', 'layouts.app'))

@section('title', $post->seoTitle().' — '.config('ai-blog.seo.site_name'))

@push('head')
    <x-ai-blog::meta-tags :post="$post" />
    <x-ai-blog::structured-data :post="$post" />
@endpush

@section('content')
<article class="ai-blog ai-blog-post">
    <header>
        <h1>{{ $post->title }}</h1>
        <time datetime="{{ $post->published_at?->toIso8601String() }}">{{ $post->published_at?->toFormattedDateString() }}</time>
        @if ($image = \ByteMinus\AiBlog\Seo\StructuredData::imageUrl($post))
            <img class="ai-blog-featured" src="{{ $image }}" alt="{{ $post->featured_image_alt }}" decoding="async" fetchpriority="high">
        @endif
    </header>

    <x-ai-blog::post-body :post="$post" />

    @if ($related->isNotEmpty())
        <aside class="ai-blog-related">
            <h2>Related</h2>
            <ul>
                @foreach ($related as $r)
                    <li><a href="{{ route('ai-blog.show', $r->slug) }}">{{ $r->title }}</a></li>
                @endforeach
            </ul>
        </aside>
    @endif
</article>
@endsection
