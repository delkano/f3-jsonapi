# F3-JsonAPI

## Introduction
I've had to write a few JsonAPI servers and, at some point, I've started to distil all the common code into a separate class. This is still a work in progress and will be for a while, since it is both my first library and a rewrite of those common classes I was talking about; but it should be operative.

## Use

### Dependencies
f3-jsonapi is a [FatFreeFramework](https://fatfreeframework.com/) plugin. These instructions assume you are working in a FatFreeFramework project.

In addition, you need to have [F3-Cortex](https://github.com/ikkez/f3-cortex) installed and configured.

### Installation
You can install f3-jsonapi in your project with:

    composer require delkano/f3-jsonapi

### Configuration
Then list all your models into your config.ini

    [models]
    <plural>=<Model>

Define each model in the namespace `Model`, using F3-Cortex for this, and finally call the setup method in your index.php before `$f3->run()`:

    JsonApi::setup();

I have added to the F3-Cortex model definition one attribute for the 'has-many' relationships. If you add

    'async' => true,

the relationship won't be detailed inline. This is useful for very large relationships.

You don't need to define any controllers, since each model is assigned the `readable` controller by default. However, if you want to customize the behaviour, you can extend any of the provided base controllers  (`JsonApi`, `Readable` and `Restricted`) and override their methods.

For ease of editing, there are some hooks provided:

    processInput
    postSave
    processSingleQuery
    processListQuery
    orderRelationship

I will write some documentation for them, but for now you can check the JsonApi controller code to see their working.

## Current status

  * Base controller works
  * Readable controller, for those objects that can only be edited by their creators but publicly read
  * Restricted controller, for those objects that can only be accessed by their creators
  * The fallback controller, which is assigned to any models without an explicit controller, defaults to Readable
  * Setup is written
  * Needs testing

Although F3-JsonAPI is to be considered under development, I have been using it for my own projects for some time and it is stable and working.
