<?php

/**
 * Abstracts out the display/editing feature of individual rows that are the
 * result of an arbitrary query.
 *
 * This should allow us to move the functionality of display.php out of the top
 * level and protect it with tokens much more effectively.
 *
 * $Id$
 */
class QRDisplayPaginated {

    private $sql;
    private $page = 1;

    private $options = array(
        'collapse_text' => true,
    );


}

?>
