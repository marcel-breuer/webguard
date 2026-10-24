export type TranslationMessages = Record<string, string>;

export interface LocalizeOptions {
    locale: string;
    messages: TranslationMessages;
}

function interpolate(template: string, replacements: Record<string, string>): string {
    return template.replace(/:([A-Za-z0-9_]+)/g, (_, key: string) => replacements[key] ?? `:${key}`);
}

function dynamicMessage(
    messages: TranslationMessages,
    key: string,
    replacements: Record<string, string>,
    fallback: string,
): string {
    return interpolate(messages[key] ?? fallback, replacements);
}

function translateDynamic(value: string, messages: TranslationMessages): string | undefined {
    const unread = value.match(/^Notifications, ([\d.,\u00a0\u202f]+) unread$/);

    if (unread) {
        return dynamicMessage(messages, "Notifications, :count unread", { count: unread[1] }, value);
    }

    const unreadTitle = value.match(/^Notifications \(([\d.,\u00a0\u202f]+) unread\)$/);

    if (unreadTitle) {
        return dynamicMessage(messages, "Notifications (:count unread)", { count: unreadTitle[1] }, value);
    }

    const checkedEvery = value.match(/^Checked every ([\d.,\u00a0\u202f]+) (minutes|seconds)$/);

    if (checkedEvery) {
        const unit = checkedEvery[2] === "minutes" ? "minutes" : "seconds";

        return dynamicMessage(messages, `Checked every :value ${unit}`, { value: checkedEvery[1] }, `Checked every :value ${unit}`);
    }

    const downtime = value.match(/^([\d.,\u00a0\u202f]+) (incident|incidents), ([\d.,\u00a0\u202f]+)% downtime$/);

    if (downtime) {
        const key = `:count ${downtime[2]}, :percent downtime`;

        return dynamicMessage(messages, key, { count: downtime[1], percent: downtime[3] }, value);
    }

    const loadedChecks = value.match(/^([\d.,\u00a0\u202f]+) loaded checks · Live and archived data$/);

    if (loadedChecks) {
        return dynamicMessage(messages, ":count loaded checks · Live and archived data", { count: loadedChecks[1] }, value);
    }

    const duration = value.match(/^(.* · )([\d.,\u00a0\u202f]+)(h|m)(?: ([\d.,\u00a0\u202f]+)m)?$/);

    if (duration) {
        if (duration[3] === "h" && duration[4]) {
            return `${duration[1]}${dynamicMessage(messages, ":hours h :minutes min", { hours: duration[2], minutes: duration[4] }, ":hours h :minutes min")}`;
        }

        if (duration[3] === "h") {
            return `${duration[1]}${dynamicMessage(messages, ":hours h", { hours: duration[2] }, ":hours h")}`;
        }

        return `${duration[1]}${dynamicMessage(messages, ":minutes min", { minutes: duration[2] }, ":minutes min")}`;
    }

    const firstResults = value.match(/^The first monitoring results can take up to ([\d.,\u00a0\u202f]+) minutes, based on the configured check interval\.$/);

    if (firstResults) {
        return dynamicMessage(
            messages,
            "The first monitoring results can take up to :minutes minutes, based on the configured check interval.",
            { minutes: firstResults[1] },
            value,
        );
    }

    const since = value.match(/^Since (.+)$/);

    if (since) {
        return dynamicMessage(messages, "Since :value", { value: since[1] }, value);
    }

    const validUntil = value.match(/^Valid until (.+)$/);

    if (validUntil) {
        return dynamicMessage(messages, "Valid until :value", { value: validUntil[1] }, value);
    }

    const starts = value.match(/^Starts (.+)$/);

    if (starts) {
        return dynamicMessage(messages, "Starts :value", { value: starts[1] }, value);
    }

    const availabilityFor = value.match(/^Availability for (.+)$/);

    if (availabilityFor) {
        return dynamicMessage(messages, "Availability for :value", { value: availabilityFor[1] }, value);
    }

    const lastChecked = value.match(/^Last checked (.+)$/);

    if (lastChecked) {
        return dynamicMessage(messages, "Last checked :value", { value: lastChecked[1] }, value);
    }

    const lastDays = value.match(/^Last ([\d.,\u00a0\u202f]+) days$/);

    if (lastDays) {
        return dynamicMessage(messages, "Last :value days", { value: lastDays[1] }, value);
    }

    const incidentCount = value.match(/^([\d.,\u00a0\u202f]+) incidents?$/);

    if (incidentCount) {
        const count = Number(incidentCount[1].replace(/[.,\u00a0\u202f]/g, ""));
        const key = count === 1 ? ":count incident" : ":count incidents";

        return dynamicMessage(messages, key, { count: incidentCount[1] }, value);
    }

    const groupSummary = value.match(/^([\d.,\u00a0\u202f]+) monitorings · ([\d.,\u00a0\u202f]+) healthy(?: · ([\d.,\u00a0\u202f]+) down)?$/);

    if (groupSummary) {
        const key = groupSummary[3]
            ? ":monitorings monitorings · :healthy healthy · :down down"
            : ":monitorings monitorings · :healthy healthy";

        return dynamicMessage(messages, key, {
            monitorings: groupSummary[1],
            healthy: groupSummary[2],
            down: groupSummary[3] ?? "",
        }, value);
    }

    const statusPageSummary = value.match(/^([\d.,\u00a0\u202f]+) components · ([\d.,\u00a0\u202f]+) monitorings$/);

    if (statusPageSummary) {
        return dynamicMessage(messages, ":components components · :monitorings monitorings", {
            components: statusPageSummary[1],
            monitorings: statusPageSummary[2],
        }, value);
    }

    const namedMonitorings = value.match(/^(.* · )([\d.,\u00a0\u202f]+) monitorings$/);

    if (namedMonitorings) {
        return `${namedMonitorings[1]}${dynamicMessage(messages, ":count monitorings", { count: namedMonitorings[2] }, namedMonitorings[0])}`;
    }

    const pagination = value.match(/^([\d.,\u00a0\u202f]+)–([\d.,\u00a0\u202f]+) of ([\d.,\u00a0\u202f]+) incidents$/);

    if (pagination) {
        return dynamicMessage(messages, ":from–:to of :total incidents", {
            from: pagination[1],
            to: pagination[2],
            total: pagination[3],
        }, value);
    }

    const showing = value.match(/^Showing ([\d.,\u00a0\u202f]+) of ([\d.,\u00a0\u202f]+) entries$/);

    if (showing) {
        return dynamicMessage(messages, "Showing :count of :total entries", { count: showing[1], total: showing[2] }, value);
    }

    const totalMonitorings = value.match(/^Total monitorings: ([\d.,\u00a0\u202f]+)$/);

    if (totalMonitorings) {
        return dynamicMessage(messages, "Total monitorings: :count", { count: totalMonitorings[1] }, value);
    }

    const websites = value.match(/^([\d.,\u00a0\u202f]+) websites$/);

    if (websites) {
        return dynamicMessage(messages, ":count websites", { count: websites[1] }, value);
    }

    const monitorings = value.match(/^([\d.,\u00a0\u202f]+) monitorings$/);

    if (monitorings) {
        return dynamicMessage(messages, ":count monitorings", { count: monitorings[1] }, value);
    }

    const minutes = value.match(/^([\d.,\u00a0\u202f]+) min$/);

    if (minutes) {
        return dynamicMessage(messages, ":minutes min", { minutes: minutes[1] }, value);
    }

    const compactDuration = value.match(/^([\d.,\u00a0\u202f]+) h(?: ([\d.,\u00a0\u202f]+) min)?$/);

    if (compactDuration) {
        return compactDuration[2]
            ? dynamicMessage(messages, ":hours h :minutes min", { hours: compactDuration[1], minutes: compactDuration[2] }, value)
            : dynamicMessage(messages, ":hours h", { hours: compactDuration[1] }, value);
    }

    return undefined;
}

export function translate(value: string, locale: string, messages: TranslationMessages = {}): string {
    if (locale !== "de") return value;

    const leading = value.match(/^\s*/)?.[0] ?? "";
    const trailing = value.match(/\s*$/)?.[0] ?? "";
    const content = value.trim();
    const translated = messages[content]
        ?? translateDynamic(content, messages)
        ?? (content.endsWith(" | Status pages | WebGuard")
            ? `${content.slice(0, -" | Status pages | WebGuard".length)} | Statusseiten | WebGuard`
            : content);

    return `${leading}${translated}${trailing}`;
}

const sourceText = new WeakMap<Node, string>();
const sourceAttributes = new WeakMap<Element, Map<string, string>>();
let sourceTitle: string | null = null;

function localizeTextNode(node: Node, options: LocalizeOptions): void {
    const currentValue = node.nodeValue ?? "";
    const sourceValue = sourceText.get(node);
    const value = sourceValue === undefined || (currentValue !== sourceValue && currentValue !== translate(sourceValue, "de", options.messages))
        ? currentValue
        : sourceValue;

    sourceText.set(node, value);

    const translated = translate(value, options.locale, options.messages);

    if (translated !== node.nodeValue) node.nodeValue = translated;
}

function localizeElement(element: Element, options: LocalizeOptions): void {
    if (element.closest("pre, code, script, style")) return;

    for (const attribute of ["aria-label", "placeholder", "title"]) {
        const currentValue = element.getAttribute(attribute);

        if (currentValue === null) continue;

        const attributes = sourceAttributes.get(element) ?? new Map<string, string>();
        const sourceValue = attributes.get(attribute);
        const value = sourceValue === undefined || (currentValue !== sourceValue && currentValue !== translate(sourceValue, "de", options.messages))
            ? currentValue
            : sourceValue;

        attributes.set(attribute, value);
        sourceAttributes.set(element, attributes);

        const translated = translate(value, options.locale, options.messages);

        if (translated !== currentValue) element.setAttribute(attribute, translated);
    }

    for (const child of element.childNodes) {
        if (child.nodeType === Node.TEXT_NODE) {
            localizeTextNode(child, options);
        } else if (child.nodeType === Node.ELEMENT_NODE) {
            localizeElement(child as Element, options);
        }
    }
}

export function localize(node: HTMLElement, initialOptions: LocalizeOptions): { update(nextOptions: LocalizeOptions): void; destroy(): void } {
    let options = initialOptions;

    const observer = new MutationObserver((records) => {
        for (const record of records) {
            if (record.type === "characterData") {
                localizeTextNode(record.target, options);
                continue;
            }

            if (record.type === "attributes") {
                localizeElement(record.target as Element, options);
                continue;
            }

            for (const addedNode of record.addedNodes) {
                if (addedNode.nodeType === Node.TEXT_NODE) {
                    localizeTextNode(addedNode, options);
                } else if (addedNode.nodeType === Node.ELEMENT_NODE) {
                    localizeElement(addedNode as Element, options);
                }
            }
        }
    });

    const apply = (): void => {
        document.documentElement.lang = options.locale;

        localizeElement(node, options);
        sourceTitle ??= document.title;
        const translatedTitle = translate(sourceTitle, options.locale, options.messages);

        if (translatedTitle !== document.title) document.title = translatedTitle;
    };

    apply();
    observer.observe(node, {
        attributeFilter: ["aria-label", "placeholder", "title"],
        attributes: true,
        characterData: true,
        childList: true,
        subtree: true,
    });

    return {
        update(nextOptions: LocalizeOptions): void {
            options = nextOptions;
            apply();
        },
        destroy(): void {
            observer.disconnect();
        },
    };
}
