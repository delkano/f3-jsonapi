<?php
/**
 * This class is in charge of creating routes, trivial controllers and so
 */
class JsonAPI {
    static public function setup() {
        $f3 = \Base::instance();
        // Get Models from Config.ini
        $cruds = $f3->models;

        foreach($cruds as $crud => $obj) {
            // For each one, see if a Controller exists. If not, it will be handled by the fallback
            $obj = "\Controller\\$obj";
            if(class_exists($obj)) {
                // Create the routes for it.
                $f3->route("GET @${crud}: /api/${crud}/@id", "${obj}->getOne");
                $f3->route("GET @${crud}: /api/${crud}", "${obj}->getList");
                $f3->route("POST @${crud}: /api/${crud}", "${obj}->create");
                $f3->route("PATCH @${crud}: /api/${crud}/@id", "${obj}->update");
                $f3->route("DELETE @${crud}: /api/${crud}/@id", "${obj}->delete");
                $f3->route("GET @${crud}_relationships: /api/${crud}/@id/relationships/@relationship", "${obj}->relationships");
                $f3->route("GET @${crud}_related: /api/${crud}/@id/@related", "${obj}->related");
            }
        }

        // Fallbacks
        $f3->route("GET /api/@plural/@id", "\Controller\\Fallback->getOne");
        $f3->route("GET /api/@plural", "\Controller\\Fallback->getList");
        $f3->route("POST /api/@plural", "\Controller\\Fallback->create");
        $f3->route("PATCH /api/@plural/@id", "\Controller\\Fallback->update");
        $f3->route("DELETE /api/@plural/@id", "\Controller\\Fallback->delete");
        $f3->route("GET /api/@plural/@id/relationships/@relationship", "\Controller\\Fallback->relationships");
        $f3->route("GET /api/@plural/@id/@related", "\Controller\\Fallback->related");
    }
}
