---
layout: post
title: Documenting A Spring Boot Microservice Using Spring REST Docs
permalink: /blog/spring-rest-doc/
author: steven_heyninck
date:   2015-11-16
header: documenting.jpg
---

Last september, [Andy Wilkinson](https://twitter.com/ankinson),
[Spring IO Platform](https://spring.io/platform) lead at cloud-native platform company
[Pivotal](http://pivotal.io/) gave a talk in Washington DC at
[SpringOne2GX](http://www.springone2gx.com/) about 'Documenting Restful APIs'
([slides](https://2015.event.springone2gx.com/schedule/sessions/documenting_restful_apis.html)).

See this webinar for reference:

{% youtube knH5ihPNiUs %}

He explained the pro’s and cons of working with a popular tool like Swagger and came up with an
interesting alternative approach of writing tests to document your API. Sounds like a clear win-win to me.

Regarding an API and what you should document, Andy proposes the usual suspects:

* the accepted input,
* the produced output and
* a clear definition of what resource it represents and
* links (where can you go to find what exactly)

Another interesting concept Andy put forward is to document cross-cutting concerns on a general
documentation level, avoiding repeating yourself in every single API call documentation. Candidates to
make it into the top-level documentation are concerns like:

* HTTP status codes,
* HTTP verbs (and the difference between PUT and PATCH),
* rate limiting,
* authentication and authorisation

So far so good, I’m all in :-)

Next up was a statement about URI-centric documentation not being the way to go. Having URI’s left and
center is disturbing as they are not the prime way you - as a developer - think about the API when you’re
trying to figure out how to use it. Ok, agreed. Andy goes on to explain that this is one of the downsides
of using Swagger. Although you get a lot out of it for little effort, you end up with URI-centric
documentation. Another point of criticism is that Swagger introduces a set of new annotations, which does
not really contribute to a more pleasing development experience (annotation overload anyone?), although
I consider this argument as being rather subjective.

The alternative that Andy presents is to generate documentation by writing tests for your API, which is
exactly what [Spring Rest Docs](http://projects.sping.io/spring-restdocs) is all about:

* writing as much as possible in a format that is designed for writing
* not using the implementation to provide the documentation (annotations)
* providing guarantees that the documentation is up to date (TDD)

The Spring Rest Docs project uses (the highly underestimated) [ASCIIDoctor](http://asciidoctor.org),
Spring MVC Test and is compatible with both Maven and Gradle.

Convinced that the above sounds promising and conforms to the way I’ve grown used to work, I decided to
give it a try. For that, I figured I’d need a simple Rest-based microservice and next use the Spring Rest
Docs approach to whip up my ‘living’ API documentation.

So here it goes.

## Creating The Person Microservice

In this blog post I build a standard CRUD based microservice, using Spring Boot and Gradle, for a Person
resource, using an in-memory h2 datastore and the necessary steps you need to take to have automatically
generated, nice looking, up to date, documentation for this service.

The code for this Person service can be found on our [ToThePoint GitHub repository](https://github.com/tothepoint-itco/person-service).

These are the steps I will walk you through iin detail:

1. Develop the Person service
  1. Person model
  2. Person repository
  3. Person controller
2. Add Spring Rest Docs to the mix
  1. Provide the top-level documentation
  2. Person controller test (generates documentation snippets)
  3. Include those snippets in the top-level documentation
  4. Have the documentation automatically generated and added as static doc in the executable jar

Let’s build the Person service, starting with the build.gradle file:

``` /person-service/build.gradle ```

```gradle
buildscript {
    ext {
        springBootVersion = '1.2.7.RELEASE'
        springRestDocsVersion = '1.0.0.RELEASE'
    }
    repositories {
        mavenCentral()
    }
    dependencies {
        classpath("org.springframework.boot:spring-boot-gradle-plugin:${springBootVersion}")
        classpath('io.spring.gradle:dependency-management-plugin:0.5.2.RELEASE')
    }
}

apply plugin: 'java'
apply plugin: 'idea'
apply plugin: 'spring-boot'
apply plugin: 'io.spring.dependency-management'

jar {
    baseName = 'person-service'
    version = '0.0.1-SNAPSHOT'
}
sourceCompatibility = 1.8
targetCompatibility = 1.8

repositories {
    mavenCentral()
}


dependencies {
    compile("org.springframework.boot:spring-boot-starter-web:${springBootVersion}")
    compile('org.springframework.boot:spring-boot-starter-data-jpa')
    compile('com.h2database:h2')

}

task wrapper(type: Wrapper) {

    gradleVersion = '2.7'
}
```

We apply the java and idea plugin, and also add the spring boot plugin to the mix and set the source and
target compatibility to 1.8.

Adding the mavenCentral repository and the dependencies on spa and H2 (we’ll use the H2 in-memory db to
store people in) gets up ready to go.

## Application

The microservice itself then: since we are building a Spring Boot application, we’ll need an Application
class and a configuration file.

``` /person-service/src/main/java/company/tothepoint/demo/service/person/Application.java ```

```java

package company.tothepoint.demo.service.person;

import org.springframework.boot.SpringApplication;
import org.springframework.boot.autoconfigure.SpringBootApplication;
import org.springframework.boot.builder.SpringApplicationBuilder;
import org.springframework.boot.context.web.SpringBootServletInitializer;

@SpringBootApplication
public class Application extends SpringBootServletInitializer {
    public static void main(String[] args) {
        SpringApplication.run(Application.class, args);
    }

    @Override
    protected SpringApplicationBuilder configure(SpringApplicationBuilder application) {
        return application.sources(Application.class);
    }
}
```

The application.yml file gives our microservice its name:

``` /person-service/src/main/resources/application.yml ```

```yaml

spring:
  application:
    name: person-service
```

## Person Model And Repository

``` /person-service/src/main/java/company/tothepoint/demo/service/person/model/Person.java ```

```java

package company.tothepoint.demo.service.person.model;

import javax.persistence.Entity;
import javax.persistence.GeneratedValue;
import javax.persistence.Id;
import javax.validation.constraints.NotNull;
import javax.validation.constraints.Size;

@Entity
public class Person {
    @Id
    @GeneratedValue
    private Long id;

    @NotNull
    @Size(min = 1, max = 20)
    private String firstName;

    @NotNull
    @Size(min = 1, max = 50)
    private String lastName;

    public Person() {
    }

    public Person(String firstName, String lastName) {
        this.firstName = firstName;
        this.lastName = lastName;
    }

    public Long getId() {
        return id;
    }

    public void setId(Long id) {
        this.id = id;
    }

    public String getFirstName() {
        return firstName;
    }

    public void setFirstName(String firstName) {
        this.firstName = firstName;
    }

    public String getLastName() {
        return lastName;
    }

    public void setLastName(String lastName) {
        this.lastName = lastName;
    }
}
```

It is a basic entity, with a generated id and 2 attributes, firstName and lastName, on which I added some
constraints, which we'll use later on in our documentation.

To handle our persistence needs, we’ll make use of a JPA CrudRepository, as shown here:

``` /person-service/src/main/java/company/tothepoint/demo/service/person/repository/PersonRepository.java ```

```java

package company.tothepoint.demo.service.person.repository;

import company.tothepoint.demo.service.person.model.Person;
import org.springframework.data.repository.CrudRepository;

public interface PersonRepository extends CrudRepository<Person, Long> {}
```

As you see, this is merely an interface, the implementation is automatically provided by Spring.

## Person Controller

We will be implementing the following REST endpoints:

* POST to /people passing in a JSON payload to create a Person and return an HTTP Status 201 ‘Created'
* GETting from /people to fetch the list of people
* GETting from /people/{id} to fetch a specific person
* and PUTting to /people/{id} passing a JSON payload to update an existing Person

Here is our controller doing just that:

``` /person-service/src/main/java/company/tothepoint/demo/service/person/controller/PersonController.java ```

```java

package company.tothepoint.demo.service.person.controller;

import company.tothepoint.demo.service.person.model.Person;
import company.tothepoint.demo.service.person.repository.PersonRepository;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.http.HttpStatus;
import org.springframework.web.bind.annotation.*;

@RestController
@RequestMapping("/people")
public class PersonController {
    @Autowired private PersonRepository personRepository;

    @RequestMapping(value = "", method = RequestMethod.GET)
    public Iterable<Person> listPeople() {
        return personRepository.findAll();
    }

    @RequestMapping(value="/{id}", method = RequestMethod.GET)
    public Person getPerson(@PathVariable("id") Long id) {
        return personRepository.findOne(id);
    }

    @RequestMapping(value = "", method = RequestMethod.POST)
    @ResponseStatus(HttpStatus.CREATED)
    public void createPerson(@RequestBody Person person) {
        personRepository.save(new Person(person.getFirstName(), person.getLastName()));
    }

    @RequestMapping(value = "/{id}", method = RequestMethod.PUT)
    @ResponseStatus(HttpStatus.NO_CONTENT)
    public void updatePerson(@PathVariable("id") Long id, @RequestBody Person person) {
        Person existingPerson = personRepository.findOne(id);
        existingPerson.setFirstName(person.getFirstName());
        existingPerson.setLastName(person.getLastName());
        personRepository.save(existingPerson);
    }
}
```

This is all pretty standard Spring, nothing fancy at all, straightforward.

## Fire Up The Application

Once you get here, you have the Application, the Model, the Repository and a Controller, this means you
have a working Application that you can launch right away:

```bash
cd person-service
gradle bootRun
```

should get you up and running on the standard port 8080, the last lines should show you something like:

```bash
2015-11-04 10:31:44.477INFO 26593 --- [main] s.b.c.e.t.TomcatEmbeddedServletContainer : Tomcat started on port(s): 8080 (http)

2015-11-04 10:31:44.479INFO 26593 --- [main] c.t.demo.service.person.Application    : Started Application in 5.911 seconds (JVM running for 6.32)
```

Up and running? Let’s continue then...

## First Doing Some Manual Tests

Fire up [Postman](https://www.getpostman.com/) to insert a new Person and the fetch the list of people:

![POSTing a JSON payload to the /people endpoint for the new Person with first name 'Postman' and last name 'always rings twice'](/img/blog/spring-rest-doc/posting-json-payload.png)

![Fetching all people by GETting from the /people endpoint](/img/blog/spring-rest-doc/fetching-all-people.png)

If this worked for you, we can move on to the meat of this post, documenting this API by ...

## ... Adding Spring REST Docs

### Extending Our Gradle Build File

For this we need to add some lines to our build.gradle file we’ve created earlier:

* add the AsciiDoctor plugin
* tell Spring Rest Docs to generate documentation snippets in the ```build/generated-snippets``` folder
* tell AsciiDoctor to look for asciidoc source file in the ```src/main/asciidoc``` folder
* tell AsciiDoctor to look for generated documentation snippets in the ```build/generated-snippets``` folder
* add a testCompile dependency to ```org.springframework.boot:spring-boot-starter-test```
* add a testCompile dependency to ```org.springframework.restdocs:spring-restdocs-mockmvc```
* make the AsciiDoctor task dependent on the test task
* make the jar task dependent on the AsciiDoctor task
* put the AsciiDoctor output inside the jar in the static/docs folder


``` build.gradle ```

```gradle

  buildscript {
      ext {
          springBootVersion = '1.2.7.RELEASE'
          springRestDocsVersion = '1.0.0.RELEASE'
      }
      repositories {
          mavenCentral()
      }
      dependencies {
          classpath("org.springframework.boot:spring-boot-gradle-plugin:${springBootVersion}")
          classpath('io.spring.gradle:dependency-management-plugin:0.5.2.RELEASE')
      }
  }

  plugins {
      id "org.asciidoctor.convert" version "1.5.2"
  }

  apply plugin: 'java'
  apply plugin: 'idea'
  apply plugin: 'spring-boot'
  apply plugin: 'io.spring.dependency-management'

  jar {
      baseName = 'person-service'
      version = '0.0.1-SNAPSHOT'
      dependsOn asciidoctor
      from ("${asciidoctor.outputDir}/html5") {
          into 'static/docs'
      }
  }
  sourceCompatibility = 1.8
  targetCompatibility = 1.8

  repositories {
      mavenCentral()
  }


  dependencies {
      compile("org.springframework.boot:spring-boot-starter-web:${springBootVersion}")
      compile('org.springframework.boot:spring-boot-starter-data-jpa')
      compile('com.h2database:h2')
      testCompile('org.springframework.boot:spring-boot-starter-test')
      testCompile("org.springframework.restdocs:spring-restdocs-mockmvc:${springRestDocsVersion}")
  }

  ext {
      snippetsDir = file('build/generated-snippets')
  }


  test {
      outputs.dir snippetsDir
  }


  asciidoctor {
      attributes 'snippets': snippetsDir
      inputs.dir snippetsDir
      outputDir "build/asciidoc"
      dependsOn test
      sourceDir 'src/main/asciidoc'
  }

  task wrapper(type: Wrapper) {
      gradleVersion = '2.7'
  }

```

### Adding A Main Documentation File

The principle used is to provide a main documentation file, which contains references to generated documentation snippets. The documentation snippet generation is the outcome of running tests. The main documentation file itself is completely up to you to structure, but a sensible default might look like this:

* Introduction
* Overview
  * HTTP Verbs
  * HTTP Status codes

In the introduction you would describe your microservice.

The overview of HTTP Verbs and HTTP Status codes defines which verbs you use (PUT, PATCH or both, and what you exactly mean by them, taking away any ambiguity) and what the meaning is of the different HTTP Status codes.

Next you structure your main documentation file per resource it contains. In our example case there is only 1 resource, a Person.

Per resource you dedicate a section per API call, referencing the documentation snippet which will be generated by the MockMVC tests you write for that API call.

* Resources
  * <Resource 1>
    * <API Call 1 for Resource 1>
    * ...

For our Person service the main documentation file currently looks like this:

``` src/main/asciidoc/index.adoc ```

```adoc

  = Person-service Getting Started Guide
  Jane Doe;
  :doctype: book
  :icons: font
  :source-highlighter: highlightjs
  :toc: left
  :toclevels: 4
  :sectlinks:

  [introduction]
  = Introduction

  Person-service is a RESTful microservice for ...

  [[overview]]
  = Overview

  [[overview-http-verbs]]
  == HTTP verbs
  Person-service tries to adhere as closely as possible to standard HTTP and REST conventions in its
  use of HTTP verbs.
  |===
  | Verb | Usage

  | `GET`
  | Used to retrieve a resource

  | `POST`
  | Used to create a new resource

  | `PATCH`
  | Used to update an existing resource, including partial updates

  | `PUT`
  | Used to update an existing resource, full updates only

  | `DELETE`
  | Used to delete an existing resource
  |===

  [[overview-http-status-codes]]
  == HTTP status codes
  Person-service tries to adhere as closely as possible to standard HTTP and REST conventions in its
  use of HTTP status codes.

  |===
  | Status code | Usage

  | `200 OK`
  | Standard response for successful HTTP requests.
  | The actual response will depend on the request method used.
  | In a GET request, the response will contain an entity corresponding to the requested resource.
  | In a POST request, the response will contain an entity describing or containing the result of the action.

  | `201 Created`
  | The request has been fulfilled and resulted in a new resource being created.

  | `204 No Content`
  | The server successfully processed the request, but is not returning any content.

  | `400 Bad Request`
  | The server cannot or will not process the request due to something that is perceived to be a client error (e.g., malformed request syntax, invalid request message framing, or deceptive request routing).

  | `404 Not Found`
  | The requested resource could not be found but may be available again in the future. Subsequent requests by the client are permissible.
  |===

  [[resources]]
  = Resources


  [[resources-person]]
  == Person
  The Person resource is used to create, modify and list people.
```

For now, we have a Resource section in here, but no detailed documentation about any of this resource's API
calls yet.

Now that we have the main doc in place, it's time to start documenting our resource, or to be precise:
start writing tests for our resource. With Spring Rest Docs, documenting means writing tests.

### Documenting Our Person Resource By Writing A First Test For It

The PersonControllerTest class should provide a test for each of the API calls. Let's write the first one, a test for the GETting of all people in our repository:

``` src/test/java/company/tothepoint/demo/service/person/controller/PersonControllerTest.java ```

```java

package company.tothepoint.demo.service.person.controller;

import company.tothepoint.demo.service.person.Application;
import company.tothepoint.demo.service.person.model.Person;
import company.tothepoint.demo.service.person.repository.PersonRepository;
import org.junit.Before;
import org.junit.Rule;
import org.junit.Test;
import org.junit.runner.RunWith;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.boot.test.SpringApplicationConfiguration;
import org.springframework.http.MediaType;
import org.springframework.restdocs.RestDocumentation;
import org.springframework.restdocs.mockmvc.RestDocumentationResultHandler;
import org.springframework.test.context.junit4.SpringJUnit4ClassRunner;
import org.springframework.test.context.web.WebAppConfiguration;
import org.springframework.test.web.servlet.MockMvc;
import org.springframework.test.web.servlet.setup.MockMvcBuilders;
import org.springframework.web.context.WebApplicationContext;

import static org.springframework.restdocs.mockmvc.MockMvcRestDocumentation.document;
import static org.springframework.restdocs.mockmvc.MockMvcRestDocumentation.documentationConfiguration;
import static org.springframework.restdocs.operation.preprocess.Preprocessors.*;
import static org.springframework.restdocs.payload.PayloadDocumentation.fieldWithPath;
import static org.springframework.restdocs.payload.PayloadDocumentation.responseFields;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.get;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.status;

@RunWith(SpringJUnit4ClassRunner.class)
@SpringApplicationConfiguration(classes = Application.class)
@WebAppConfiguration
public class PersonControllerTest {
    @Rule
    public final RestDocumentation restDocumentation = new RestDocumentation("build/generated-snippets");

    @Autowired
    private WebApplicationContext context;

    @Autowired
    private PersonRepository personRepository;

    @Autowired
    private ObjectMapper objectMapper;

    private MockMvc mockMvc;

    private RestDocumentationResultHandler document;

    @Before
    public void setUp() {
        this.document = document("{method-name}", preprocessRequest(prettyPrint()), preprocessResponse(prettyPrint()));
        this.mockMvc = MockMvcBuilders.webAppContextSetup(this.context)
                .apply(documentationConfiguration(this.restDocumentation))
                .alwaysDo(this.document)
                .build();
    }

    @Test
    public void listPeople() throws Exception {
        createSamplePerson("George", "King");
        createSamplePerson("Mary", "Queen");

        this.document.snippets(
                responseFields(
                        fieldWithPath("[].id").description("The persons' ID"),
                        fieldWithPath("[].firstName").description("The persons' first name"),
                        fieldWithPath("[].lastName").description("The persons' last name")
                )
        );

        this.mockMvc.perform(
                get("/people").accept(MediaType.APPLICATION_JSON)
        ).andExpect(status().isOk());
    }

    private Person createSamplePerson(String firstName, String lastName) {
        return personRepository.save(new Person(firstName, lastName));
    }

}
```

The test class is annotated with both @RunWith and @SpringApplicationConfiguration to have the JUnit and
Spring Boot functionality available. Since we're testing using MockMvc we tell Spring that we're testing
a web app, using the ```@WebAppConfiguration``` annotation.

JUnit ```@Rule``` annotation instructs Spring Rest Doc where to put the generated documentation snippets.

The WebApplicationContext and the PersonRepository are wired in, so we can create sample people in our test.

In the test setup:

* we define that the generated snippets will use the method name as a name
* we indicate that we want both the request and the response pretty-printed
* we instantiate a MockMvc instance, instructing Spring to call the RestDocumentationHandler for it during
test execution
* The test itself creates 2 Person instances, and instructs Spring Rest Doc how to structure the
documentation snippet during test execution. Maybe a bit of weird syntax is the [].firstName which
actually means each result's firstName.

Next it performs the actual API call and asserts that the returned HTTP status is correct.

Running the above test (gradle test) generates 4 documentation snippets, which will be found in the
build/generated-snippets/list-people folder:

* curl-request.adoc
  * an example curl call to test this API call
* http-request.adoc
  * the complete GET request the test performed
* http-response.adoc
  * the response received by the test
* response-fields.adoc
  * a table containing all the response fields expected by the test

### Add The Generated Snippets To the Main Doc

Going back to the main documentation file, we should now include directives in there to insert these snippets:

```adoc

 [[resource-people-list]]
=== Listing people
A `GET` request lists all of the service's people.

include::{snippets}/list-people/response-fields.adoc[]

==== Example request

include::{snippets}/list-people/curl-request.adoc[]

==== Example response

include::{snippets}/list-people/http-response.adoc[]
The snippets placeholder is replaced by Spring Rest Doc to the build/generated-snippets folder.
```

Running the asciidoctor gradle task now:

``` gradle asciidoctor ```

This results in a new file, in the ```build/asciidoc/html5/index.html``` folder, looking like this:

![HTML5 asciidoc result](/img/blog/spring-rest-doc/result-page.png)

### Time To Add The Rest Of The Tests Now

We add tests for GETting a specific Person resource, for POSTing a new Person and PUTting an updated Person.
The complete PersonRestController ends up looking like this:

``` src/test/java/company/tothepoint/demo/service/person/controller/PersonControllerTest.java ```

```java

  package company.tothepoint.demo.service.person.controller;

  import com.fasterxml.jackson.databind.ObjectMapper;
  import company.tothepoint.demo.service.person.Application;
  import company.tothepoint.demo.service.person.model.Person;
  import company.tothepoint.demo.service.person.repository.PersonRepository;
  import org.junit.Before;
  import org.junit.Rule;
  import org.junit.Test;
  import org.junit.runner.RunWith;
  import org.springframework.beans.factory.annotation.Autowired;
  import org.springframework.boot.test.SpringApplicationConfiguration;
  import org.springframework.http.MediaType;
  import org.springframework.restdocs.RestDocumentation;
  import org.springframework.restdocs.constraints.ConstraintDescriptions;
  import org.springframework.restdocs.mockmvc.RestDocumentationResultHandler;
  import org.springframework.restdocs.payload.FieldDescriptor;
  import org.springframework.test.context.junit4.SpringJUnit4ClassRunner;
  import org.springframework.test.context.web.WebAppConfiguration;
  import org.springframework.test.web.servlet.MockMvc;
  import org.springframework.test.web.servlet.setup.MockMvcBuilders;
  import org.springframework.util.StringUtils;
  import org.springframework.web.context.WebApplicationContext;

  import java.util.HashMap;
  import java.util.Map;

  import static org.springframework.restdocs.mockmvc.MockMvcRestDocumentation.document;
  import static org.springframework.restdocs.mockmvc.MockMvcRestDocumentation.documentationConfiguration;
  import static org.springframework.restdocs.operation.preprocess.Preprocessors.*;
  import static org.springframework.restdocs.payload.PayloadDocumentation.fieldWithPath;
  import static org.springframework.restdocs.payload.PayloadDocumentation.requestFields;
  import static org.springframework.restdocs.payload.PayloadDocumentation.responseFields;
  import static org.springframework.restdocs.snippet.Attributes.key;
  import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.get;
  import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.post;
  import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.put;
  import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.status;

  @RunWith(SpringJUnit4ClassRunner.class)
  @SpringApplicationConfiguration(classes = Application.class)
  @WebAppConfiguration
  public class PersonControllerTest {
      @Rule
      public final RestDocumentation restDocumentation = new RestDocumentation("build/generated-snippets");

      @Autowired
      private WebApplicationContext context;

      @Autowired
      private PersonRepository personRepository;

      @Autowired
      private ObjectMapper objectMapper;

      private MockMvc mockMvc;

      private RestDocumentationResultHandler document;

      @Before
      public void setUp() {
          this.document = document("{method-name}", preprocessRequest(prettyPrint()), preprocessResponse(prettyPrint()));
          this.mockMvc = MockMvcBuilders.webAppContextSetup(this.context)
                  .apply(documentationConfiguration(this.restDocumentation))
                  .alwaysDo(this.document)
                  .build();
      }

      @Test
      public void listPeople() throws Exception {
          createSamplePerson("George", "King");
          createSamplePerson("Mary", "Queen");

          this.document.snippets(
                  responseFields(
                          fieldWithPath("[].id").description("The persons' ID"),
                          fieldWithPath("[].firstName").description("The persons' first name"),
                          fieldWithPath("[].lastName").description("The persons' last name")
                  )
          );

          this.mockMvc.perform(
                  get("/people").accept(MediaType.APPLICATION_JSON)
          ).andExpect(status().isOk());
      }

      @Test
      public void getPerson() throws Exception {
          Person samplePerson = createSamplePerson("Henry", "King");

          this.document.snippets(
                  responseFields(
                          fieldWithPath("id").description("The person's ID"),
                          fieldWithPath("firstName").description("The persons' first name"),
                          fieldWithPath("lastName").description("The persons' last name")
                  )
          );

          this.mockMvc.perform(
                  get("/people/" + samplePerson.getId()).accept(MediaType.APPLICATION_JSON)
          ).andExpect(status().isOk());
      }

      @Test
      public void createPerson() throws Exception {
          Map<String, String> newPerson = new HashMap<>();
          newPerson.put("firstName", "Anne");
          newPerson.put("lastName", "Queen");

          ConstrainedFields fields = new ConstrainedFields(Person.class);

          this.document.snippets(
                  requestFields(
                          fields.withPath("firstName").description("The persons' first name"),
                          fields.withPath("lastName").description("The persons' last name")
                  )
          );

          this.mockMvc.perform(
                  post("/people").contentType(MediaType.APPLICATION_JSON).content(
                          this.objectMapper.writeValueAsString(newPerson)
                  )
          ).andExpect(status().isCreated());
      }

      @Test
      public void updatePerson() throws Exception {
          Person originalPerson = createSamplePerson("Victoria", "Queen");
          Map<String, String> updatedPerson = new HashMap<>();
          updatedPerson.put("firstName", "Edward");
          updatedPerson.put("lastName", "King");

          ConstrainedFields fields = new ConstrainedFields(Person.class);

          this.document.snippets(
                  requestFields(
                          fields.withPath("firstName").description("The persons' first name"),
                          fields.withPath("lastName").description("The persons' last name")
                  )
          );

          this.mockMvc.perform(
                  put("/people/" + originalPerson.getId()).contentType(MediaType.APPLICATION_JSON).content(
                          this.objectMapper.writeValueAsString(updatedPerson)
                  )
          ).andExpect(status().isNoContent());
      }

      private Person createSamplePerson(String firstName, String lastName) {
          return personRepository.save(new Person(firstName, lastName));
      }

      private static class ConstrainedFields {

          private final ConstraintDescriptions constraintDescriptions;

          ConstrainedFields(Class<?> input) {
              this.constraintDescriptions = new ConstraintDescriptions(input);
          }

          private FieldDescriptor withPath(String path) {
              return fieldWithPath(path).attributes(key("constraints").value(StringUtils
                      .collectionToDelimitedString(this.constraintDescriptions
                              .descriptionsForProperty(path), ". ")));
          }
      }
  }
```

### Again Extend Our Main Doc With The Newly Generated Snippets

Those extra tests generate extra code snippets which we can now include in our main documentation file:

``` src/main/asciidoc/index.adoc ```

```adoc

= Person-service Getting Started Guide
Jane Doe;
:doctype: book
:icons: font
:source-highlighter: highlightjs
:toc: left
:toclevels: 4
:sectlinks:

[introduction]
= Introduction

Person-service is a RESTful microservice for ...

[[overview]]
= Overview

[[overview-http-verbs]]
== HTTP verbs
Person-service tries to adhere as closely as possible to standard HTTP and REST conventions in its
use of HTTP verbs.
|===
| Verb | Usage

| `GET`
| Used to retrieve a resource

| `POST`
| Used to create a new resource

| `PATCH`
| Used to update an existing resource, including partial updates

| `PUT`
| Used to update an existing resource, full updates only

| `DELETE`
| Used to delete an existing resource
|===

[[overview-http-status-codes]]
== HTTP status codes
Person-service tries to adhere as closely as possible to standard HTTP and REST conventions in its
use of HTTP status codes.

|===
| Status code | Usage

| `200 OK`
| Standard response for successful HTTP requests.
| The actual response will depend on the request method used.
| In a GET request, the response will contain an entity corresponding to the requested resource.
| In a POST request, the response will contain an entity describing or containing the result of the action.

| `201 Created`
| The request has been fulfilled and resulted in a new resource being created.

| `204 No Content`
| The server successfully processed the request, but is not returning any content.

| `400 Bad Request`
| The server cannot or will not process the request due to something that is perceived to be a client error (e.g., malformed request syntax, invalid request message framing, or deceptive request routing).

| `404 Not Found`
| The requested resource could not be found but may be available again in the future. Subsequent requests by the client are permissible.
|===

[[resources]]
= Resources


[[resources-person]]
== Person
The Person resource is used to create, modify and list people.


[[resource-people-list]]
=== Listing people
A `GET` request lists all of the service's people.

include::{snippets}/list-people/response-fields.adoc[]

==== Example request

include::{snippets}/list-people/curl-request.adoc[]

==== Example response

include::{snippets}/list-people/http-response.adoc[]


[[resource-people-get]]
=== Fetching people
A `GET` request fetches a specific person.

include::{snippets}/get-person/response-fields.adoc[]

==== Example request

include::{snippets}/get-person/curl-request.adoc[]

==== Example response

include::{snippets}/get-person/http-response.adoc[]


[[resource-people-create]]
=== Creating people
A `POST` request creates a new person.

==== Example request

include::{snippets}/create-person/curl-request.adoc[]

==== Example response

include::{snippets}/create-person/http-response.adoc[]


[[resource-people-update]]
=== Updating people
A `PUT` request updates an existing person.

==== Example request

include::{snippets}/update-person/curl-request.adoc[]

==== Example response

include::{snippets}/update-person/http-response.adoc[]

```

Running the tests again now yields a completely documented resource.

### Building The JAR File

Build the executable jar file by running a gradle build:

``` gradle build ```

Next, execute this jar:

``` java -jar build/libs/person-service-0.0.1-SNAPSHOT.jar ```

and point your browser to http://localhost:8080/static/docs to check your generated documentation.

Let's now turn our attention to the bean validation constraints (@Size and @NotNull), which are not
included in the documentation by default.

### Documenting Bean Validation Constraints

In the test we've added to test creation of people, you notice that we use the ConstrainedFields class.
This helper class is used to build FieldDescriptors that contain the bean validation constraints.

Let's go through the specifics of this POSTing test:

```java

import com.fasterxml.jackson.databind.ObjectMapper;
...
@Autowired
private ObjectMapper objectMapper;
...
@Test
public void createPerson() throws Exception {
    Map<String, String> newPerson = new HashMap<>();
    newPerson.put("firstName", "Anne");
    newPerson.put("lastName", "Queen");

    ConstrainedFields fields = new ConstrainedFields(Person.class);
    this.document.snippets(
        requestFields(
            fields.withPath("firstName").description("The persons' first name"),
            fields.withPath("lastName").description("The persons' last name")
    )
);
...

        this.mockMvc.perform(
                post("/people").contentType(MediaType.APPLICATION_JSON).content(
                        this.objectMapper.writeValueAsString(newPerson)
                )
        ).andExpect(status().isCreated());
    }
```

We create the payload for the POST call by creating a HashMap with a new Person's attributes in it, which
the JSON ObjectMapper converts to JSON. The actual call is performed and the resulting HTTP status
code gets asserted. You see that - apart from constructing the POST payload - it is not very different
from the GET all people call.

However, the interesting part is that we use the ConstrainedFields helper class to instruct Spring Rest Docs
to also take the bean validation constraints into account. This helper class in itself does only part of
the work, since the default asciidoc template for request field descriptors is not listing those extra
constraints. This is where we have to manually intervene by overriding that default template, which is a
pity really.

Add a new snippet template for this:

``` /src/test/resources/org/springframework/restdocs/templates/request-fields.snippet ```

```
|===
{% raw %}
|Path|Type|Description|Constraints

{{#fields}}

|{{Path}}
|{{Type}}
|{{Description}}
|{{Constraints}}

{{/fields}}
|===
{% endraw %}
```

The extra constraints column is what we added to the default template.

Now running the tests again:

```gradle test```

and inspecting the generated snippet:

``` build/generated-snippets/create-person/request-fields.adoc ```

```
|===
|Path|Type|Description|Constraints

|firstName
|String
|The person's first name
|Must not be null. Size must be between 1 and 20 inclusive

|lastName
|String
|The person's last name
|Must not be null. Must be between 1 and 50 inclusive
```

you see that the constraints are now being generated in the snippet.

The only thing left to do is list it in our main documentation file, in both the POST and PUT calls:

``` src/main/asciidoc/index.adoc ```

```
[[resource-people-create]]
=== Creating people
A `POST` request creates a new person.

==== Request structure

include::{snippets}/create-person/request-fields.adoc[]

==== Example request

include::{snippets}/create-person/curl-request.adoc[]

==== Example response

include::{snippets}/create-person/http-response.adoc[]


[[resource-people-update]]
=== Updating people
A `PUT` request updates an existing person.

==== Request structure

include::{snippets}/create-person/request-fields.adoc[]

==== Example request

include::{snippets}/update-person/curl-request.adoc[]

==== Example response

include::{snippets}/update-person/http-response.adoc[]
```

Running the gradle asciidoctor task will produce the documentation, including the bean validation
constraints in it:

![ASCII Doctor result](/img/blog/spring-rest-doc/asciidoctor.png)

## Interesting Links

* [Spring Rest Docs](http://projects.spring.io/spring-restdocs/)
* [Postman](https://www.getpostman.com)
* [Asciidoctor](http://asciidoctor.org)
* [Gradle](http://gradle.org)
* [Spring Boot](http://projects.spring.io/spring-boot/)