<?php
namespace Controller;

/**
 * This Class is not meant to be extended. It's for internal use only.
 */
class Fallback extends Restricted {
    public function beforeroute($f3,$args) {
        if($f3->exists("models.${args['plural']}"))
            $this->model = $f3['models'][$args['plural']];
        else $f3->error(404, "The requested object does not exist.");
   }
}
