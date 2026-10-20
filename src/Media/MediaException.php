<?php

namespace ByteMinus\AiBlog\Media;

use RuntimeException;

/**
 * A media upload or attachment that could not be done. The message is written for the person (or agent)
 * who asked, and never carries a path, a credential or a temporary download address.
 */
class MediaException extends RuntimeException {}
