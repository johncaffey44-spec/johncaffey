import Foundation

/// Lecture « texte » d'une configuration WireGuard (format wg-quick : fichier .conf
/// ou QR code généré par Freebox OS). Utilisé par l'application ET par l'extension.
enum WgQuick {
    typealias Section = [String: [String]]

    struct Parsed {
        var interface: Section?
        var peers: [Section] = []
    }

    struct Summary {
        var endpoint: String?
        var addresses: [String]
        var dns: [String]
        var allowedIPs: [String]
        var peerCount: Int

        var fullTunnel: Bool { allowedIPs.contains("0.0.0.0/0") || allowedIPs.contains("::/0") }
        var endpointHost: String? {
            guard let e = endpoint, let idx = e.lastIndex(of: ":") else { return endpoint }
            return String(e[..<idx]).trimmingCharacters(in: CharacterSet(charactersIn: "[]"))
        }
        var endpointPort: String? {
            guard let e = endpoint, let idx = e.lastIndex(of: ":") else { return nil }
            return String(e[e.index(after: idx)...])
        }
    }

    static func looksLikeWireGuard(_ text: String) -> Bool {
        let t = text.lowercased()
        return t.contains("[interface]") && t.contains("[peer]") && t.contains("privatekey")
    }

    static func parse(_ text: String) -> Parsed {
        var result = Parsed()
        var current: Section?
        var currentName = ""

        func flush() {
            guard let c = current else { return }
            if currentName == "[interface]" { result.interface = c }
            else if currentName == "[peer]" { result.peers.append(c) }
        }

        for raw in text.components(separatedBy: .newlines) {
            let line = raw.split(separator: "#", maxSplits: 1, omittingEmptySubsequences: false).first
                .map { String($0).trimmingCharacters(in: .whitespaces) } ?? ""
            if line.isEmpty { continue }
            if line.hasPrefix("[") && line.hasSuffix("]") {
                flush()
                currentName = line.lowercased()
                current = [:]
                continue
            }
            guard let eq = line.firstIndex(of: "=") else { continue }
            let key = line[..<eq].trimmingCharacters(in: .whitespaces).lowercased()
            let value = line[line.index(after: eq)...].trimmingCharacters(in: .whitespaces)
            current?[key, default: []].append(value)
        }
        flush()
        return result
    }

    /// Découpe « a, b, c » en ["a", "b", "c"].
    static func list(_ values: [String]?) -> [String] {
        (values ?? []).flatMap { $0.split(separator: ",") }
            .map { $0.trimmingCharacters(in: .whitespaces) }
            .filter { !$0.isEmpty }
    }

    static func summary(_ text: String) -> Summary {
        let p = parse(text)
        return Summary(
            endpoint: p.peers.first?["endpoint"]?.first,
            addresses: list(p.interface?["address"]),
            dns: list(p.interface?["dns"]),
            allowedIPs: p.peers.flatMap { list($0["allowedips"]) },
            peerCount: p.peers.count
        )
    }
}
