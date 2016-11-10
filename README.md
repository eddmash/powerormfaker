# PowerOrmFaker

PowerOrmFake is an extension of Faker library that generates fake data for the PowerOrm Library. 
Its depends on [Faker Library](https://packagist.org/packages/fzaninotto/faker) 


## Installation

```sh
composer require eddmash/powerormfaker

## Populating Models

PowerOrmFaker eases the population of databases through the Model classes provided by PowerOrm library.

To populate models, create a new populator class (using a generator instance as parameter), then list the class and 
number of all the models that must be generated. 

To launch the actual data population, call the `execute()` method.

Here is an example showing how to populate 5 `Author` and 10 `Book` objects:

```php
<?php
$generator = \Faker\Factory::create();
$populator = new Eddmash\PowerOrmFaker\Populator($generator);
$populator->addModel(new Author, 5);
$populator->addModel(new Book, 10);
$insertedPKs = $populator->execute();
```

The populator uses name and column type guessers to populate each column with relevant data. For instance, Faker 
populates a column named `first_name` using the `firstName` formatter, and a column with a `TIMESTAMP` type using the 
`dateTime` formatter. The resulting models are therefore coherent. If Faker misinterprets a column name, 
you can still specify a custom closure to be used for populating a particular column, using the third 
argument to `addModel()`:

```php
<?php
$populator->addModel('Book', 5, array(
  'ISBN' => function() use ($generator) { return $generator->ean13(); }
));
```

In this example, Faker will guess a formatter for all columns except `ISBN`, for which the given anonymous function 
will be used.

**Tip**: To ignore some columns, specify `null` for the column names in the third argument of `addModel()`. 
This is usually necessary for columns added by a behavior:

```php
<?php
$populator->addModel('Book', 5, array(
  'CreatedAt' => null,
  'UpdatedAt' => null,
));
```

Of course, Faker does not populate autoincremented primary keys. 
In addition, `Eddmash\PowerOrmFaker\Populator::execute()` returns the list of inserted PKs, indexed by class:

```php
<?php
print_r($insertedPKs);
// array(
//   'Author' => (34, 35, 36, 37, 38),
//   'Book'   => (456, 457, 458, 459, 470, 471, 472, 473, 474, 475)
// )
```

In the previous example, the `Book` and `Author` models share a relationship. 
Since `Author` models are populated first, Faker is smart enough to relate the populated `Book` models to one of the 
populated `Author` models.

Lastly, if you want to execute an arbitrary function on an entity before insertion, use the fourth argument of the 
`addModel()` method:

```php
<?php
$populator->addModel('Book', 5, array(), array(
  function($book) { $book->publish(); },
));
```
