_Latest stable release:_
[![Lint](https://github.com/PhotoboothProject/photobooth/actions/workflows/lint.yml/badge.svg?branch=stable4)](https://github.com/PhotoboothProject/photobooth/actions/workflows/lint.yml)
[![gulp-sass](https://github.com/PhotoboothProject/photobooth/actions/workflows/gulp_sass.yml/badge.svg?branch=stable4)](https://github.com/PhotoboothProject/photobooth/actions/workflows/gulp_sass.yml)
[![Build](https://github.com/PhotoboothProject/photobooth/actions/workflows/build.yml/badge.svg?branch=stable4)](https://github.com/PhotoboothProject/photobooth/actions/workflows/build.yml)

_Latest development version:_
[![Lint](https://github.com/PhotoboothProject/photobooth/actions/workflows/lint.yml/badge.svg)](https://github.com/PhotoboothProject/photobooth/actions/workflows/lint.yml)
[![gulp-sass](https://github.com/PhotoboothProject/photobooth/actions/workflows/gulp_sass.yml/badge.svg)](https://github.com/PhotoboothProject/photobooth/actions/workflows/gulp_sass.yml)
[![Build](https://github.com/PhotoboothProject/photobooth/actions/workflows/build.yml/badge.svg)](https://github.com/PhotoboothProject/photobooth/actions/workflows/build.yml)

---

![](https://raw.githubusercontent.com/PhotoboothProject/photobooth/dev/resources/img/logo/banner.png)

---

# Photobooth

A Photobooth web interface for Linux and Windows.

Photobooth was initially developped by Andre Rinas to use on a Raspberry Pi, you can find his source [here](https://github.com/andreknieriem/photobooth).
In 2019 Andreas Skomski picked up the work and continued to work on the source.
With the help of the community Photobooth grew to a powerfull Photobooth software with a lot of features and possibilities.


# Contribute to this Webpage

Clone the _photobooth_ project from github. Run the following commands from your Terminal:

```sh
git clone https://github.com/PhotoboothProject/photobooth.git
cd photobooth/docs
```

Make your changes inside the _docs/_ directory, upload them to your fork and create a [pull request](https://github.com/PhotoboothProject/photobooth/pulls).

---

# Changelog

View all version changes [here](changelog.md).

---

## News

### 22. December 2024

Hey together!

This year almost comes to an end.

I am glad to see the community is growing every day and I am proud about the support and help from everyone! It's kind of special and unique seeing user helping each other like done in our community!

I am happy about all the contributions made to the project - especially Benjamin Kott helped a lot reworking and improving the source of Photobooth! With the improved source and code quality Photobooth runs always stable on latest development version!

There's still work left before Photobooth v5 can be released as a stable release, due to changes in private life I haven't had the time needed for it.

Photobooth stays feature complete for this year in latest development source, only dependencies updates will be applied.

I hope everyone can enjoy the Christmas days and have a good start into the new year 2025!

Best regards

Andi

---

### 17. September 2024

Photobooth v4.5.1 has been released.

The release keeps Photobooth v4 compatible with latest Photobooth installer and allows automated installation tests via GitHub actions.

Besides that only dependencies have been updated to latest version.

The changed Onoff library should be able to handle the changed GPIO sysfs on latest PiOS kernel.


We're still working on rewriting the Photobooth source for the upcoming v5 release - there's still some work left before the new version can be released.

The current development version runs nicely and stable for most user! We are always happy about feedback and helping hands!

New feature have been added already and the UI got an overhaul in a lot of places.

Like always: The full Changelog can be found [here](changelog.md).

If you're running latest development version already: there's no need to install Photobooth v4.5!

Your Photobooth-Team

---

### 09. January 2024

It's been a while, but here's some news outside of our Community on Telegram.

Actually the source code of Photobooth is rewritten in almost all sections of Photobooth and there's still some work left before Photobooth v5 can be released.

Photobooth v4.4.0 was released! This release is meant as bugfix-release to fix some known bugs, retain Windows compatibility and to keep compatibility with the changed install steps on latest development version.
A few new features have made it inside this release, but more to come with the upcoming Photobooth v5!

And don't worry! The current development version runs nicely and stable for most user! We are always happy about feedback and helping hands!

Like always: The full Changelog can be found [here](changelog.md).

Your Photobooth-Team

---

### 24 December 2022

Hey everyone!

Photobooth again was improved a lot this year and a lot of user wishes have been added to the project.

Thanks to everyone for being part of this community, your feedback and your help to make Photobooth such a great OpenSource Project!

We hope you have a safe and relaxing christmas time!


Your Photobooth-Team

---

### 6 December 2022

Photobooth v4.3.1 released!

Build dependencies have been updated and the process of taking an image was improved to optimize the timings between the single actions.
The visible countdown now is independent of the time we need to take an image, the defined offset will be respected now.
Also we now don't wait for the cheese message to end, the picture will be taken without waiting for it.
A small bug was fixed, where the shutter animation was started twice if an cheese image is used.

Like always: The full Changelog can be found [here](changelog.md).


Enjoy Photobooth v4.3.1!

---

### 28 November 2022

Photobooth v4.3.0 released!

Some minor bugs have been fixed, build dependencies have been updated, new Features have been added.


Like always: The full Changelog can be found [here](changelog.md).

Enjoy Photobooth v4.3.0!

---

### 16 October 2022

Photobooth v4.2.0 released today!

Some minor bugs have been fixed, PHPMailer and build dependencies have been updated.


The full Changelog can be found [here](changelog.md).

Enjoy Photobooth v4.2.0!

---


### 30 September 2022

We're proud to release Photobooth v4.1.0!

Some bugs have been fixed, some new features have made it's way into the new version and some code have been cleaned.

Logging is added to save and reset actions via Adminpanel for easier debugging.

The full Changelog can be found [here](changelog.md).

Enjoy Photobooth v4.1.0!

---

### 10 September 2022

We're proud to release Photobooth v4.0.0 with the code switch to PhotoboothProject which contains a lot of Bugfixes and user-wishes could be integrated.

Photobooth v4.0.0 comes in a new _**modern squared**_ look!

Overall the code got optimized and cleaned up. There's also a lot of new options added.

Photobooth is now again compatible with Windows, also PHP8 won't cause trouble.

The full Changelog can be found [here](changelog.md).

If you find a bug you're welcome to report it on the [GitHub issue page](https://github.com/PhotoboothProject/photobooth/issues).

New Images with preinstalled Photobooth will be available next days.

[Update instructions](update/index.md) have been updated, you can now easily update your existing git installation of Photobooth using the photobooth installer!

Enjoy Photobooth v4.0.0!

---

### 17 August 2022

Photobooth source moved!

We’re proud to announce that our Photobooth Source moved to [https://github.com/PhotoboothProject](https://github.com/PhotoboothProject) for the upcoming Photobooth release!

Currently we’re preparing this webpage for you with all needed information. Also update/upgrade information will be available once the new release is out.

Stay tuned!

---
