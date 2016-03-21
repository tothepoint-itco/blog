---
layout: post
title: A Practial Introduction To DDD And OOD Coming From Standard Rails, Part 1
permalink: /blog/a-practical-introduction-to-ddd-and-ood-coming-from-standard-rails-part-1/
author: laurens_de_la_marche
date:   2015-11-3
header: code-1.jpg
---

This series is intended for an audience that feels comfortable with the basics of Ruby and Rails and wants
to learn how to add practical DDD and OOD principles to their toolkit to improve the quality of their code.

## Introduction

### Single Responsibility Principle (SRP)

A core concept behind good OOD and DDD is SRP: giving objects a single responsibility. By following basic
Rails religiously and not deflecting from the standard classes that Rails provides (models, controllers,
views, etc..) one tends to end up with fat classes: classes that are responsible for numerous tasks which
thus violate SRP. Concretely this often leads to fat ActiveRecord classes and fat ActionControllers.

#### Standard Ways Of Drying Up Code In Rails

Programmers wanting to DRY up their code start introducing Decorators (e.g. Draper), Presenters,
numerous Helpers, Concerns,... These do tend to give the basic Rails classes less responsibilities.
This article however does not explore these more "standard" Rails ways but will focus more on less framework
specific techniques.

#### Why?

Standard DDD and OOD are at least just as effective in splitting the code in more reusable, maintainable
and DRYer chunks. These less framework specific techniques enable a programmer to use the acquired toolkit
across frameworks. It also allows new project collaborators (under the assumption they master DDD and OOD)
to immediately start writing quality code in Rails without necessarily knowing all the Rails specific ways
of doing so.

A more comprehensive comparison of more standard ways vs the techniques further presented is not the goal
of this article. The focus stays on showing practical ways of applying DDD and OOD principles.

## Models

Models are often built around a relational table. One object, an ActiveRecord:Base is often responsible for

1. Validating data coming through controllers (validations)
2. Keeping track of the relations between the tables (e.g. has_many)
3. Keeping track of events to be fired in its lifecycle (e.g. dependent: destroy, after_saves)
4. Constructing queries on the table (e.g. scopes)
5. Doing calculations on itself or on its related objects
6. Helping out the controller (e.g. serialzing itself to_json)
7. Helping out the views
8. Communicating with other back-end services (e.g. indexing itself for ElasticSearch)
9. Giving meaning to attributes on the relational table (e.g. status: "p" means the document is printed)

If a project is small, this set of responsibilities can still be manageable in one file and be displayed on
one page. If the project gets bigger, one approach is to divide the code into concerns or modules (e.g.
gem modularity). Code can even be extracted into shared concerns or modules making the code DRYer. But
this does not take away the fact that the model still responds to all those methods and responsibilities,
it just divides the code into different files. This still violates SRP.

Luckily with common OOD and DDD techniques we can restrict the responsibilities of the Model. Reducing
the responsibilities of Rails classes is not always easy but it is desirable. You get very DRY code.
Testing becomes a lot easier, less error prone and faster. Looser coupling also means you can often
extract code from the project to include in other projects.

## Part 1: ActiveRecord: Entities, but don't forget Value Objects.

We will start with the easiest technique, the low hanging fruit of refactoring:
[Value Objects](http://martinfowler.com/bliki/ValueObject.html).

Rails is built around models. If these models correspond to relational tables this often means they model
Entities.

You can change (non-primary key) attributes of an Entitity without changing its identity

Example: a person (you can change the name of a person without changing the persons' identity)
The meaning of Value Objects does change when their attributes are changed. Examples of Value Objects:
money, decisions, printed_states, ratings.

Value objects are interchangeable, they are not mutable (if an attribute of a model changes you can just
create a new value object when you get the value), they do not track relations (e.g. which model
initialized them).

Since I think you learn best by example I included a list of examples
[here](https://github.com/ldlamarc/ExampleCode/tree/master/value_objects). I will discuss one example
here through commentary in the code but you are free to check the other examples and specs.

### Example1

Imagine you have an application that receives api_calls and stores every api_call in a database with the
field "code" that represents the HTTP response status code.

[http_status_code.rb](https://github.com/ldlamarc/ExampleCode/blob/master/value_objects/http_status_code.rb)

```ruby
module ValueObjects

  class HttpStatusCode

    SUCCESS_RANGE = [200, 299]
    REDIRECTION_RANGE = [300, 399]
    CLIENT_ERROR_RANGE = [400, 499]
    SERVER_ERROR_RANGE = [500, 599]
    ALL_RANGE = [200, 599]

    attr_reader :code

    def initialize(code)
      if Integer(code).between?(*ALL_RANGE)
        @code = code
      else
        raise ArgumentError.new("Unknown HTTP Status Code")
      end
    end

    def success?
      between?(SUCCESS_RANGE)
    end

    def redirection?
      between?(REDIRECTION_RANGE)
    end

    def client_error?
      between?(CLIENT_ERROR_RANGE)
    end

    def server_error?
      between?(SERVER_ERROR_RANGE)
    end

    def ==(http_status_code)
      self.code == http_status_code.code
    end

    def to_s
      "#{http_status_code}"
    end

    def inspect
      to_s
    end

    private

    def between?(range)
      code.between?(*range)
    end

  end
end
```


[api_call.rb](https://github.com/ldlamarc/ExampleCode/blob/master/value_objects/example_usage_in_rails/api_call.rb)

```ruby
#This code serves strictly as example code on how to integrate value_objects in typical Rails code and the pros and cons.
#It has not been tested or used as is in a real world application

class ApiCall < ActiveRecord::Base
  belongs_to :person
  has_many :input_errors

  #With ValueObjects
    def http_response_code
      @code ||= ValueObjects::HttpStatusCode.new(read_attribute(:code))
      #Advantages
        #Can serve as a good building block for a other Objects (e.g. a generic StatusPresenter[1] which translates success? to a green/red light in a View)
      #Disadvantages:
        #You need an extra method to couple the value_object, an extra class, spec, file and probably folder.
    end

    def success?
      http_response_code.success? || input_errors.empty?
    end

  #Without ValueObjects
    def success?
      http_response_success? || input_errors.empty?
      #Disadvantages:
        #Stubbing http_response_success? in a unit test is with a boolean is very method and implementation specific, stubbing http_response_code with a HttpStatusCode.new(200) seems to be a better option
    end

    def http_response_success?
      code.between?(200,299)
      #Disadvantages:
        #This includes HTTP protocol constants which do not belong in your api_call Model
          #Knowledge is required about non-api_call constants by every developer who will change/expand/refactor api_call methods
          #The code is closely coupled to those constants. If the constants change this code will have to change
        #This code is not reusable across models (workaround could be to include it in a Module)
        #Your model needs an extra method per http code (Fat Model):
          #Increased risk of method name conflicts
          #Increased risk of developers using other formats/names for similar methods (e.g. http_client_error? instead of http_response_client_error?)
          #Increased risk of developers not knowing the existence of a method (hidden in a module or because the model code is too long to read)
          #Extra test per method
          #Extra time lost in reading/finding/comprehending a method
        #The method is very closely coupled to your database (if the field "code" is renamed or changed to a string you would need to change this method)
    end

  #[1] http://railscasts.com/episodes/287-presenters-from-scratch

end
```

The above code can handle responsibility "9/Giving meaning to attributes on the relational table" which we discussed earlier. That's already one less responsibility for our model. As you can see the code is quite generic. You can easily use this code across models or even projects.

Testing becomes very easy and fast:

[http_status_code_spec.rb](https://github.com/ldlamarc/ExampleCode/blob/master/value_objects/spec/http_status_code_spec.rb)

```ruby
require 'spec_helper'

module ValueObjects
  describe HttpStatusCode do

    let(:code_200){HttpStatusCode.new(200)}
    let(:code_500){HttpStatusCode.new(500)}

    describe "#success?" do
      context "200" do
        it "returns true" do
          expect(code_200.success?).to eq true
        end
      end
    end

    describe "#server_error?" do
      context "200" do
        it "returns false" do
          expect(code_200.server_error?).to eq false
        end
      end
    end
  end
end
```

### Extra Tips And Tricks

* Value objects can also be good building blocks to tackle other aforementioned responsibilities
of the model.
* You can namespace your value_objects (e.g via a module) and put them in a separate folder. This will
make your code very clear for new project collaborators that might have heard of value objects. You can
even choose to distinguish reusable value_objects from domain specific objects. Not namespacing will
make your code shorter but might introduce confusion or errors (other developers adding mutability to
value_objects) and might make reusing the value objects more difficult.
* Initializing a value object with multiple attributes is also a possibility. The only precaution to
take is to not give the value object too much responsibilities and to keep in mind that a value object
is not an entity. It does not have an identity or relations, only properties.
* If you use rspec "require 'spec_helper'" is often sufficient to test, no need to include 'rails_helper'.
* Since it's good practice to keep your value_objects immutable do not implement any setters.
* It is good practice to define the == method. Value objects are equal if their attributes are equal.
* A lot of value objects can benefit from including the
[Comparable module](http://ruby-doc.org/core-2.2.3/Comparable.html).
* Implementing the inspect method is a good practice. It permits you to have better output in the
console and it allows you to easily set attributes on a record
(e.g. course.academic_year = AcademicYear.new("2012/2013"))
* [composed_of](http://api.rubyonrails.org/classes/ActiveRecord/Aggregations/ClassMethods.html) is an
interesting Rails method that can be used to couple your value_objects to your ActiveRecord