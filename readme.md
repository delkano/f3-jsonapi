# F3-JsonAPI

## Introduction
I've had to write a few JsonAPI servers and, at some point, I've started to distil all the common code into a separate class. This is still a work in progress and will be for a while, since it is both my first library and a rewrite of those common classes I was talking about; but it should quickly be operative.

## Use

The objective is that you install this with 

    composer require delkano/f3-jsonapi

Then list all your models into your config.ini

    [models]
    <plural>=<Model>

Define each model in the namespace `Model`, using F3-Cortex for this, and finally call the setup method in your index.php before `$f3->run()`:

    JsonApi::setup();

You don't need to define any controllers, since each model is assigned the `readable` controller by default. However, if you want to customize the behaviour, you can extend any of the provided base controllers  (`JsonApi`, `Readable` and `Restricted`) and override their methods.

For ease of editing, there are some hooks provided:

    processInput
    postSave
    processSingleQuery
    processListQuery
    orderRelationship

I will write some documentation for them, but for now you can check the JsonApi controller code to see their working.

## Current status

  * The base controller is mostly written, only lacking some query parameters (pagination, includes and so)
  * A readable controller, for those objects that can only be edited by their creators but pubicly read, is written but untested
  * A restricted controller, for those objects that can only be accessed by their creators, is written but untested
  * The fallback controller, which is assigned to any models without an explicit controller, defaults to readable
  * Setup is written
