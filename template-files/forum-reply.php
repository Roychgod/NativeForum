<?php namespace ProcessWire;
/**
 * NativeForum Template
 * Replies are normally viewed in-topic; redirect to parent topic.
 */
/** @var Page $page */
if($page->parent && $page->parent->id) {
  $url = $page->parent->url . '#reply-' . (int)$page->id;
  $session->redirect($url);
}
throw new Wire404Exception();
