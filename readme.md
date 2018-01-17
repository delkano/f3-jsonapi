# F3-JsonAPI

## Introduction
I've had to write a few JsonAPI servers and, at some point, I've started to distil all the common code into a separate class. This is still a work in progress and will be for a while, since it is both my first library and a rewrite of those common classes I was talking about; but it should quickly be operative.

The objective is that you install this with 

    composer require delkano/f3-jsonapi

Then list all your models into your config.ini

    [models]
    <plural>=<Model>

Define each model in the namespace `Model`, using F3-Cortex for this, and finally call the setup method in your index.php before `$f3->run()`:

    JsonApi::setup();

## Current status

  * The base controller is mostly written, only lacking some query parameters (pagination, includes and so)
  * A restricted controller, for those objects that can only be accessed by their creators, is written but untested
  * Setup is written but yet untested
