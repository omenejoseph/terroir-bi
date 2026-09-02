# Component map — Figma → Vue

The design's own component vocabulary, derived by counting `<instance>` and
repeated frame names across all 54 cached frames (`frames/**/*.xml`). This is
what the design actually reuses, not a taxonomy invented for the port.

Every Vue component below names its Figma counterpart in its own docblock, so
the mapping survives without anyone consulting this file.

## Published Figma components (`<instance>` nodes)

| Figma | Uses | Sections | Vue |
|---|---|---|---|
| `Field` | 156 | 5 | `ui/FormField.vue` |
| `Badge` | 22 | 3 | `ui/Badge.vue` |
| `Switch` | 16 | 4 | `ui/Switch.vue` |
| `lucide/*` | 40+ | 2+ | `lucide-vue-next` |

`Field` being the most-instanced component by a wide margin is why form layout
gets its own primitives (`FormField`, `FieldRow`, `FormSection`) rather than
ad-hoc markup per screen.

## Recurring structures (repeated frame names)

| Figma | Uses | Sections | Vue |
|---|---|---|---|
| `Button` | 654 | 6 | `ui/Button.vue` |
| `Separator` | 142 | 6 | `ui/Separator.vue` |
| `TabsTab` / `TabsRoot` | 98 / 16 | 4 | `ui/Tabs.vue` |
| `Card` / `CardHeader` / `CardContent` / `CardTitle` | 66 / 68 / 77 / 40 | 3–4 | `ui/Card*.vue` |
| `FieldRow` | 40 | 5 | `ui/FieldRow.vue` |
| `FieldControl` | 39 | 5 | `ui/Input.vue`, `ui/Select.vue`, `ui/Textarea.vue` |
| `CheckboxRoot` | 35 | 3 | `ui/Checkbox.vue` |
| `FormSection` | 29 | 3 | `ui/FormSection.vue` |
| `AvatarRoot` / `AvatarFallback` | 28 | 5 | `ui/Avatar.vue` |
| `Kbd` | 27 | 5 | `ui/Kbd.vue` |
| `Heading 1` | 27 | 5 | `ui/PageHeader.vue` |
| `SectionHeader` | 24 | 3 | `ui/SectionHeader.vue` |
| `FormHeader` / `FormBody` / `FormFooter` | 22 each | 6 | the three regions of `ui/SidePanel.vue` |
| `Disclosure` / `DisclosureHeader` / `DisclosureBody` | 18 / 9 / 9 | 5 | `ui/Disclosure.vue` |
| `SwitchRow` | 16 | 4 | `ui/SwitchRow.vue` |
| `MetaStrip` | 8 | 4 | `ui/MetaStrip.vue` |
| `Panel` | 7 | 3 | `ui/SidePanel.vue` |
| `Input · Search` | 5 | 3 | *not extracted yet* |

## Not built yet

Each is a real design component that a later phase needs:

| Figma | Uses | Needed by |
|---|---|---|
| `PreviewCardTrigger` | 225 | the collapsed `NavRail` hover cards |
| `Stepper` / `Step · *` | 9 + | Orders status timeline |
| `LineItem` | 12 | Order and Purchase Order line tables |
| `TableHeader` / `TableFooter` | 10 / 5 | the extracted `DataTable` |
| `ChartContainer` | 8 | Analytics screens |
| `LookupField` | 5 | customer/product pickers |
| `Dot` | 12 | status indicators |
| `NavRail` / `ShortcutsFlyout` | 25 each | the collapsed nav state |

## Two deliberate departures

**`Tabs` merges `TabsRoot`/`TabsTab` into one component with two variants.** The
design draws the same control two ways — underlined for a module's
sub-navigation, segmented for in-card range pickers. They are variants of one
component rather than two components, so a tab strip cannot drift between
screens.

**`Toggle` was renamed `Switch`** to match the design's own name. Names that
disagree with the design are a slow tax on every future conversation about it.

## Rule

Before writing markup for a new screen, grep the cached frames for the frame
names it uses:

```bash
grep -o 'name="[A-Z][^"]*"' docs/design/frames/<section>/<screen>.xml | sort | uniq -c | sort -rn
```

If a name appears in this file, use the mapped component. If it appears three or
more times across sections and is *not* here, it probably deserves extracting
before the screen is built.
