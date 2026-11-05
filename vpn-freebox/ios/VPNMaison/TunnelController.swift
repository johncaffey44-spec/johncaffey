import Foundation
import NetworkExtension

/// Pilote le profil VPN d'iOS (Réglages > VPN) et l'extension PacketTunnel.
@MainActor
final class TunnelController: ObservableObject {
    static let extensionBundleId = "com.johncaffey.vpnmaison.tunnel"
    private static let configKey = "wgQuickConfig"

    @Published private(set) var manager: NETunnelProviderManager?
    @Published private(set) var status: NEVPNStatus = .invalid
    @Published private(set) var summary: WgQuick.Summary?
    @Published private(set) var connectedDate: Date?
    @Published var errorMessage: String?

    private var observer: NSObjectProtocol?

    init() {
        Task { await load() }
    }

    var hasConfig: Bool { summary != nil }

    func load() async {
        do {
            let managers = try await NETunnelProviderManager.loadAllFromPreferences()
            manager = managers.first
            observeStatus()
            refresh()
        } catch {
            errorMessage = "Impossible de lire les réglages VPN : \(error.localizedDescription)"
        }
    }

    /// Vérifie puis enregistre la configuration. iOS affiche alors
    /// « VPN Maison souhaite ajouter des configurations VPN » : répondre « Autoriser ».
    func importConfig(_ raw: String) async throws {
        let text = raw.replacingOccurrences(of: "\r\n", with: "\n").trimmingCharacters(in: .whitespacesAndNewlines) + "\n"
        guard WgQuick.looksLikeWireGuard(text) else { throw ImportError.notWireGuard }
        let s = WgQuick.summary(text)
        guard s.peerCount == 1, s.endpoint != nil else { throw ImportError.unsupported }

        let m = manager ?? NETunnelProviderManager()
        let proto = NETunnelProviderProtocol()
        proto.providerBundleIdentifier = Self.extensionBundleId
        proto.serverAddress = s.endpoint ?? "Freebox"
        // Remarque : l'application WireGuard officielle range la clé dans le trousseau (Keychain).
        // Ici, elle est confiée aux réglages VPN protégés d'iOS, accessibles seulement par cette app.
        proto.providerConfiguration = [Self.configKey: text]
        m.protocolConfiguration = proto
        m.localizedDescription = "VPN Maison (Freebox)"
        m.isEnabled = true
        try await m.saveToPreferences()
        try await m.loadFromPreferences()
        manager = m
        observeStatus()
        refresh()
    }

    func connect() {
        guard let manager else { return }
        Task {
            do {
                if !manager.isEnabled {
                    manager.isEnabled = true
                    try await manager.saveToPreferences()
                }
                try manager.connection.startVPNTunnel()
            } catch {
                errorMessage = "Connexion impossible : \(error.localizedDescription)"
            }
        }
    }

    func disconnect() {
        manager?.connection.stopVPNTunnel()
    }

    func deleteConfig() async {
        disconnect()
        try? await manager?.removeFromPreferences()
        manager = nil
        refresh()
    }

    private func observeStatus() {
        if let observer { NotificationCenter.default.removeObserver(observer) }
        guard let connection = manager?.connection else { return }
        observer = NotificationCenter.default.addObserver(
            forName: .NEVPNStatusDidChange, object: connection, queue: .main
        ) { [weak self] _ in
            Task { @MainActor in self?.refresh() }
        }
    }

    private func refresh() {
        status = manager?.connection.status ?? .invalid
        connectedDate = manager?.connection.connectedDate
        let text = (manager?.protocolConfiguration as? NETunnelProviderProtocol)?
            .providerConfiguration?[Self.configKey] as? String
        summary = text.map(WgQuick.summary)
    }

    enum ImportError: LocalizedError {
        case notWireGuard, unsupported
        var errorDescription: String? {
            switch self {
            case .notWireGuard:
                return "Ce n'est pas une configuration WireGuard. Dans Freebox OS, utilisez l'icône QR code de l'utilisateur WireGuard."
            case .unsupported:
                return "La configuration doit contenir un seul serveur (votre Freebox) avec une adresse « Endpoint »."
            }
        }
    }
}
