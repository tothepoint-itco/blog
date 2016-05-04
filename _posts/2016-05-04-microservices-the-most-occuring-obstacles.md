---
layout: post
title: "Microservices: The Most Occuring Obstacles"
permalink: /blog/microservices-the-most-occuring-obstacles/
author: kaj_van_der_hallen
date:   2016-05-04
header: microservices.jpg
---

The intention of this blog post is to tackle the most occuring obstacles that people find on their way to microservices.

## Shared Datastore
A big obstacle that people will find on their way to microservices, is giving every service its own datastore. This means that they do not share the same datastore with each other.

One of the problems with a shared datastore is that it is very hard to adapt the schema. Let's say that a specific service would need to update the current schema. This would mean that every other service needs to be updated as well to the new schema, since they are all using the same datastore.

The same goes for the technology used for the datastore. What if the datastore currently is an SQL database, but for a specific service a non-relational database would suit better? Every single service should be adapted to the new datastore.

These cases are not only very different to deal with, but it will also lead to the fear of adapting anything to the datastore over time, and that's exactly what we want to avoid by using microservices.

Therefore, what we want to do when using microservices, is giving every service its own datastore. This way, each service can have the type of datastore that suits best for it (MySQL, Postgres, Mongo, Redis, ...) with the schema that the service would work best with.


__Advantages__
* Services are loosely coupled
* Each service can use the best suited type of datastore (SQL, NoSQL, ElasticSearch, Graph, ...)

__Disadvantages__
* Transactions that need to cross multiple services requires some effort, it is not possible to just update all datastores with a single transaction. Eventual consistency will be introduced to the system (which doesn't need to be a bad thing).
* Implementing queries that need to join information spread accross the different datastores isn't straithforward anymore (can be solved using application-side joins or by CQRS with views).
* There are multiple datastores that need to be managed


The image below (Source: [Microservices (Martin Fowler)](http://martinfowler.com/articles/microservices.html)) compares the shared datastore, used in monolithic applications, with microservices that each have their own datastore.

![In a microservices world, each service has its own datastore][mf-shared-db]

---

## Synchronous vs Asynchronous
One of the most occuring obstacles when adapting to a microservices architecture is the way of communicating between the services. In this section I will try to explain why we should prefer asynchronous communication over synchronous communication.

### Synchronous communication
Synchronous communication was never a problem in relatively small monolithic applications because it is a very simple concept to reason about. The client sends a request to the server, and the server responds to the client.

However, when using a microservices architecture, there are lots of different services communicating with each other all the time. In environments like this, synchronous communication add some difficulties to the system.

When a service calls another service, and this service does not respond in time (or does not respond at all), the calling service will also fail because it was expecting a response. This can cause a waterfall of failing calls causing the whole system to break down. Even if we assume that the called service will respond, the calling service's thread is blocked until it receives the respond. This can cause services to become slow and unresponsive.

Of course there are ways to deal with these problems, e.g. circuit breakers, but these require some extra effort to implement.

An advantage of synchronous communication is that the service receives an acknowledgement that the request was received and the corresponding action was executed.

### Asynchronous communication
When using asynchronous communication, the calling service does not wait for a response from the called service.

This obviously has the great advantage that the calling service is not dependent on the called services. If they fail, the calling service will continue to operate. Another advantage is of course that the threads of the calling services aren't blocked anymore by waiting for a response.

The image below (Source: [Enterprise Integration Patterns](http://www.enterpriseintegrationpatterns.com/patterns/messaging/Introduction.html)) illustrates the difference between synchronous and asynchronous communication

![Synchronous calls vs Asynchronous calls][sync-vs-async]

There are different approaches that use an asynchronous form of communication, each with its own advantages and disadvantages.

Asynchronous communication also allows the possibility of One-To-Many communication, where a client can send a message to multiple services at once. Whereas with synchronous communication methods, the client will need to send messages to each different service separately.

#### Notifications
The simplest form of an async communication is using Notifications. This is a service that sends a request to another service but simply does not expect a response. The service just assumes that the request is being received.

The advantage of this method is that it is very simple to implement. However, this is an async call directly to another service. This means that the service that sends the call is aware of the other service, and therefore is not loosely coupled.

#### Request/async response
The request/async response method is the asynchronous equivalent to the synchronous request/response. The client sends a request to the server, and expects a response somewhere in the future but is not waiting for it. When the server responds, a callback on the client is called that will handle the response.

The advantage of this method is that is enables a service to send a request to another service and will receive a response. It does not have the disadvantage of the synchronous variant, meaning that it is not a blocking call. However, just as with the Notifications, the service must be aware of the other service and is not loosely coupled.

Also, it is important to keep in mind that calls like this, while they may look like if they were synchronous, are asynchronous calls.

#### Message-based
An asynchronous communication method that is often used is the message-based communication. With this method, there are several channels that can be used to exchange messages. Services can publish messages on a channel, and other services that are subscribed to this channel can receive and process these messages.

Note that this method can be used for One-To-Many communication as well as for One-To-One communication.

A huge advantage to this method is that it comes with high decoupling between the services. A service just publishes a message, and is completely unaware of which services will process the message an how it will be processed. This is exactly what we want with microservices. A service should not be aware of other services and what they do with their messages.

Another advantage over the other communication methods is the baked-in message buffering. If the consumer of the channel is offline for some reason, the messages queue up until they are consumed.
With synchronous communication, both services would always need to be online, otherwise the communication traffic is lost.

Using this method it would also be possible to let the consumers respond back to the service that published the request. However this will require some extra effort, since the messages need to contain the channel ID that consumers can use to publish their responses to.

### Which one should I use?
Well, it depends. Usually asynchronous communication would fit best in a microservices environment because of the advantages discussed earlier. But there might be some cases where a synchronous communication is preferred. In these cases it is possible to use synchronous communication just for these services that really need it and use async communication for the other services.

The image below (Source: [Microservices (Chris Richardson)](https://www.nginx.com/blog/building-microservices-inter-process-communication/)) illustrates that it is perfectly possible to use multiple communication methods within a microservices environment:

![Multiple communication methods can be used within a microservices environment][nginx-multiple-comm-methods]


#### Async vs Sync: Advantages and Disadvantages

|  | Synchronous | Asynchronous |
|---:| ----------- | ------------ |
| Difficulty | Easy | Hard to get right |
| Debugging | Easier to debug | Much harder to debug |
| Performance | Slow, blocking | Fast, non-blocking |
| Reliability | Very reliable, response = feedback | No response, extra effort necessary for feedback|
| Coupling | High coupling | Loose coupling |



---

## Choreography vs Orchestration
A problem that often arises when using a microservices architecture, is logic that reaches over the different services. There are 2 ways of implementing logic like this, the Choreography method and the Orchestration method.

With the orchestration method, we have some kind of central service that contains the logic. It will communicate with the corresponding services that are involved in the logic.
In fact, what we are doing with this approach is translating the process flowcharts directly into code.
The problem that arises with the orchestration method is that we have coupling between the services, since there is one central service that is highly dependent on the other services in order to perform the logic.

A better solution would be the choreography method. With this method, each service is highly independent and will react on events. There is no central unit that is directing the services on what they need to do. Each service contains its own part of the logic.

The message-based asynchronous communication, discussed earlier in this post, is ideal for the choreography approach. Using message-based communication, services just publish domain events on message channels and if other services are interested in these events, they subscribe to the corresponding channel.

Using this approach, we achieve loose coupling. Every service subscribes to the events that it is interested in and contains its own logic.

[A ThoughtWorks publication of Jean D'Amore](https://www.thoughtworks.com/insights/blog/scaling-microservices-event-stream) explains the problems of a microservices implementation using the orchestration approach and shows how the choreography approach would've been better.

Below I cited two dependency graphs of the article. The first one shows the dependencies of the services using the orchestration approach.

![Dependency graph using the orchestration approach][tw-orchestrated]

The other image shows how the dependency graph would look like using a choreography implementation (in this case by using an event stream).

![Dependency graph using the orchestration approach][tw-choreography]

### Correlation ID
When transactions move accross different services, we need a way to keep track of all the corresponding transactions.

This is done using a correlation ID. Each received request is assigned a correlation ID. This ID is used in all the transactions between the services that are related to the initial request.

This way, it is easy to find all the corresponding transactions that happened after a request (e.g. using a logging mechanism like logstash).



[mf-shared-db]: /img/blog/microservices/decentralised-data.png

[sync-vs-async]: /img/blog/microservices/SynchronousAsynchronous.gif

[nginx-multiple-comm-methods]: /img/blog/microservices/Richardson-microservices-part3-taxi-service-1024x609.png

[tw-orchestrated]: /img/blog/microservices/Dependency_c700e7696196bdff0122edb6b046bf3d.png

[tw-choreography]:/img/blog/microservices/EventStream_0_c4cf59bbd34514090699e30b2415e42c.png
