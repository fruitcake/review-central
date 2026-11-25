---
paths:
  - '**/*.{js,jsx,ts,tsx,json,java,kt,swift,m,mm,h,gradle,plist,xml,pbxproj}'
  - '**/Podfile'
  - '**/Podfile.lock'
  - 'patches/**'
---

# React Native

A React Native app, bare or through Expo: JavaScript or TypeScript under the app's own source directory, and native projects in `android/` and `ios/` that are built into the binary people install from a store.

The stack's own rules come from the stack's own skills where the repository has them, and this guide does not restate them. The review installs none, so they are only there when the repository commits them (`.claude/skills/` or `.agents/skills/`; in a Claude review the repository's `.claude/skills/` is renamed to `.claude/skills.as-text/`, so it is read and not loaded). Then they are the authority on what good looks like here: read the `SKILL.md` of each one that covers what the diff touches, such as `react-native-best-practices` for re-renders, lists, animations, startup and native modules, and the rule or reference files it points to for the case in front of you. Where they and this guide disagree, they are the more specific. A `CLAUDE.md` or `AGENTS.md` in the repository says how this app does things; a change that goes against it is a finding of the kind it describes.

## What the step gives you

The checkout, without `node_modules/`, CocoaPods or a Gradle build: the review runs no `npm`, `yarn` or `pod install`, since those run install scripts from the pull request and its dependencies. A library's API is not there to read. Judge its use by `package.json`, the lockfile and how the repository already calls it, and leave an API question you cannot settle from that as an open question rather than a finding. There is no device, simulator or `.env`.

## Where the wiring lives

A screen, task or handler nothing reaches reads as correct code. Check the other side of each of these:

- **A screen** is reachable only when a navigator registers it (`Stack.Screen`, `Tab.Screen`, `Drawer.Screen`), under the name every `navigate()` to it uses, and with the params its `route.params` reads. A deep link reaches it only through the `linking` config.
- **A native module.** A new import of a library with native code is autolinked from `package.json`, but iOS needs the matching `Podfile.lock` change, and some libraries need lines in `AppDelegate`, `MainApplication`, `build.gradle`, `react-native.config.js` or `babel.config.js` (Reanimated's plugin, for one). JavaScript that calls a native module the binary does not have crashes at start.
- **Permissions.** A feature that uses the camera, location, Bluetooth, the microphone, notifications or background modes needs its entry in `android/app/src/main/AndroidManifest.xml` and its usage description in `ios/*/Info.plist`. A missing usage description crashes the app on iOS when it asks; a missing Android permission fails silently.
- **Background work.** Headless tasks, background fetch, background geolocation and a push handler for when the app is closed (`setBackgroundMessageHandler`) are registered in `index.js`, outside any component. Registered inside one, they do not run when the app is not open.
- **Platform files.** `Foo.ios.js` and `Foo.android.js` are one module: a change to one usually needs the same change in the other. The same for `Platform.OS` and `Platform.select` branches.
- **Patches.** `patches/` (patch-package) holds edits to a dependency at an exact version. Bumping that dependency without moving the patch makes `postinstall` fail, or quietly drops the fix.

## Security

Everything in the JavaScript bundle can be read by anyone who has the app. That includes values from `react-native-config` and other `.env` loaders, which are compiled in.

- **Secrets.** No API secret, signing key or credential with more reach than one user's own access belongs in the app. One there is a finding, whatever the variable is called.
- **Storage.** `AsyncStorage` and plain MMKV are unencrypted files. A token or personal data there is readable on a rooted or backed-up device; the Keychain and Keystore are the place (`react-native-keychain`, or MMKV with an encryption key from them).
- **Deep links and push data** are input from outside: a parameter used to navigate, fetch or act is validated as such.
- **WebView.** `originWhitelist`, `injectedJavaScript`, and an `onMessage` handler that acts on what any page posts. A WebView that loads remote content with `javaScriptEnabled` and a bridge to the app is as trusted as that content.
- **Transport.** `usesCleartextTraffic`, `android:networkSecurityConfig` that allows HTTP, or `NSAllowsArbitraryLoads` let traffic go unencrypted.
- **Logs and crash reports.** Personal data or tokens in `console.log`, Sentry breadcrumbs or crash reports leave the device.
- **Over-the-air updates** replace the app's JavaScript. Where they come from, and whether that source is checked, is the app's security boundary.

## Correctness

- **Subscriptions** (`AppState`, `Keyboard`, `NetInfo`, `BackHandler`, Bluetooth, location, timers, event emitters) are removed in the effect's cleanup. One that is not keeps running after the screen is gone, and adds a second listener every time the screen opens again.
- **After an await** the screen may be gone or the user elsewhere: a state update or `navigate()` after a request the user left is a common crash or a jump nobody asked for.
- **Offline and slow networks** are normal on a phone: a request without a timeout or an error path leaves a spinner forever.
- **Lists.** A `FlatList` or `SectionList` needs a stable `keyExtractor`; a long list inside a `ScrollView` renders all of it at once.
- **Dates and times** are the device's time zone and locale, which differ from the server's.

## Tests

Jest, with native modules mocked under `__mocks__/` or `jest.setup`. A new native library without a mock breaks every test that imports it.

## before_release

- **A store build** is needed for anything native: a new or updated library with native code, a change under `android/` or `ios/`, `Podfile.lock`, permissions, `Info.plist`, `AndroidManifest.xml`, the app's icons or splash screen. It cannot go out as an over-the-air update.
- **An over-the-air update** carries JavaScript and assets only, and lands on binaries already installed: one that calls a native module or a native API those binaries lack crashes there. Say which binary version it needs.
- **The version**: `versionCode` and `versionName` in `android/app/build.gradle`, `CFBundleShortVersionString` and `CFBundleVersion` in Xcode, raised for a store build.
- **A new `.env` key** has to be in the build environment of every variant (CI, Fastlane) before the build.
