@props(['lead', 'size' => 'size-10'])
<span {{ $attributes->class([$size, 'grid shrink-0 place-items-center rounded-full text-sm font-semibold', $lead->source->color()]) }}>{{ $lead->initials }}</span>
