<?php namespace ProcessWire;
/**
 * NativeForum: stub used to render the site's own layout (_init.php + _main.php) around the forum.
 * Not a page template — do not copy to /site/templates/.
 *
 * @var string $sfMarker
 * @var string $sfRegionId
 * @var string $sfContentVar
 * @var bool $sfRegions
 */
// Delayed output: most site profiles echo $content (configurable) in _main.php
${$sfContentVar} = $sfMarker;
if($sfContentVar !== 'content') $content = $sfMarker;
// Markup Regions: replace the content region
if($sfRegions) echo "<div id=\"{$sfRegionId}\">{$sfMarker}</div>";
