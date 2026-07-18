<section class="brand-area-two">
    <div class="container">
        @if ($title = $shortcode->title)
            <p class="brand-title">{!! BaseHelper::clean($title) !!}</p>
        @endif
        <div class="row">
            @foreach ($tabs as $tab)
                @if ($tab['image'])
                    <div class="brand-item">
                        @if ($tab['link'])
                            <a href="{{ $tab['link'] }}" target="_blank">
                                {{ RvMedia::image($tab['image'], $tab['name'], lazy: false) }}
                            </a>
                        @else
                            {{ RvMedia::image($tab['image'], $tab['name'], lazy: false) }}
                        @endif
                    </div>
                @endif
            @endforeach
        </div>
    </div>
</section>