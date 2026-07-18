<section class="about-area-two">
    <div class="container">
        <div class="about-row">
            <div class="about-content">
                @if ($subtitle = $shortcode->subtitle)
                    <span class="label">{{ $subtitle }}</span>
                @endif

                @if ($title = $shortcode->title)
                    <h2>{!! BaseHelper::clean($title) !!}</h2>
                @endif

                @if ($description = $shortcode->description)
                    <p>{!! BaseHelper::clean(nl2br($description)) !!}</p>
                @endif

                @if ($tabs)
                    <div class="about-stats">
                        @foreach ($tabs as $tab)
                            @continue(!$tab['title'])
                            <div class="about-stat">
                                @if ($tab['icon'])
                                    <div class="stat-icon">
                                        <i class="{{ $tab['icon'] }}"></i>
                                    </div>
                                @elseif ($tab['icon_image'])
                                    <div class="stat-icon">
                                        {{ RvMedia::image($tab['icon_image'], $tab['title']) }}
                                    </div>
                                @endif
                                <div class="stat-text">
                                    <h5>{!! BaseHelper::clean($tab['title']) !!}</h5>
                                    @if ($tab['description'])
                                        <p>{!! BaseHelper::clean($tab['description']) !!}</p>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
            <div class="about-image">
                @if ($image = $shortcode->image)
                    {{ RvMedia::image($image, $shortcode->title) }}
                @endif
            </div>
        </div>
    </div>
</section>