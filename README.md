Programmes Caching Library
==========================

A library to facilitate and standardize cache usage across /programmes projects.

Caching times
-------------
The library standardizes caching times, which are presented in the following table:

| Bucket                     | Time (s)   |
|----------------------------|:----------:|
| CacheInterface::NONE       | None       |
| CacheInterface::SHORT      | 60         |
| CacheInterface::NORMAL     | 300        |
| CacheInterface::MEDIUM     | 1200       |
| CacheInterface::LONG       | 7200       |
| CacheInterface::X_LONG     | 86400      |
| CacheInterface::INDEFINITE | Indefinite |

License
-------

This repository is available under the terms of the Apache 2.0 license.
View the [LICENSE file](LICENSE) file for more information.

Copyright (c) 2018 BBC