import NetworkExtension
import WireGuardKit
import os

/// Extension réseau qui fait tourner le moteur WireGuard officiel (WireGuardKit).
/// iOS la démarre quand l'utilisateur appuie sur « Connecter ».
final class PacketTunnelProvider: NEPacketTunnelProvider {

    private let log = Logger(subsystem: "com.johncaffey.vpnmaison.tunnel", category: "tunnel")

    private lazy var adapter: WireGuardAdapter = {
        WireGuardAdapter(with: self) { [log] _, message in
            log.debug("\(message, privacy: .public)")
        }
    }()

    override func startTunnel(options: [String: NSObject]?, completionHandler: @escaping (Error?) -> Void) {
        guard let proto = protocolConfiguration as? NETunnelProviderProtocol,
              let text = proto.providerConfiguration?["wgQuickConfig"] as? String else {
            completionHandler(TunnelError.missingConfiguration)
            return
        }
        let configuration: TunnelConfiguration
        do {
            configuration = try TunnelConfiguration.fromWgQuick(text, name: "Freebox")
        } catch {
            completionHandler(error)
            return
        }
        adapter.start(tunnelConfiguration: configuration) { [log] adapterError in
            if let adapterError {
                log.error("Démarrage impossible : \(String(describing: adapterError), privacy: .public)")
                completionHandler(TunnelError.adapter(String(describing: adapterError)))
            } else {
                completionHandler(nil)
            }
        }
    }

    override func stopTunnel(with reason: NEProviderStopReason, completionHandler: @escaping () -> Void) {
        adapter.stop { _ in completionHandler() }
    }

    /// L'application demande les statistiques (octets reçus/envoyés, dernier échange de clés).
    override func handleAppMessage(_ messageData: Data, completionHandler: ((Data?) -> Void)?) {
        adapter.getRuntimeConfiguration { settings in
            completionHandler?(settings?.data(using: .utf8))
        }
    }
}

enum TunnelError: LocalizedError {
    case missingConfiguration
    case invalid(String)
    case adapter(String)

    var errorDescription: String? {
        switch self {
        case .missingConfiguration: return "Configuration absente : réimportez le QR code de Freebox OS."
        case .invalid(let field): return "Configuration invalide (\(field)). Réimportez le QR code de Freebox OS."
        case .adapter(let detail): return "Le moteur WireGuard n'a pas pu démarrer (\(detail))."
        }
    }
}

extension TunnelConfiguration {
    /// Construit la configuration WireGuardKit à partir du texte wg-quick.
    static func fromWgQuick(_ text: String, name: String) throws -> TunnelConfiguration {
        let parsed = WgQuick.parse(text)
        guard let iface = parsed.interface else { throw TunnelError.invalid("[Interface] manquante") }
        guard let pkText = iface["privatekey"]?.first, let privateKey = PrivateKey(base64Key: pkText) else {
            throw TunnelError.invalid("PrivateKey")
        }
        var interface = InterfaceConfiguration(privateKey: privateKey)
        for value in WgQuick.list(iface["address"]) {
            guard let range = IPAddressRange(from: value) else { throw TunnelError.invalid("Address \(value)") }
            interface.addresses.append(range)
        }
        for value in WgQuick.list(iface["dns"]) {
            if let server = DNSServer(from: value) { interface.dns.append(server) } else { interface.dnsSearch.append(value) }
        }
        if let mtu = iface["mtu"]?.first.flatMap({ UInt16($0) }) { interface.mtu = mtu }
        if let port = iface["listenport"]?.first.flatMap({ UInt16($0) }) { interface.listenPort = port }

        var peers: [PeerConfiguration] = []
        for section in parsed.peers {
            guard let keyText = section["publickey"]?.first, let publicKey = PublicKey(base64Key: keyText) else {
                throw TunnelError.invalid("PublicKey")
            }
            var peer = PeerConfiguration(publicKey: publicKey)
            if let psk = section["presharedkey"]?.first {
                guard let key = PreSharedKey(base64Key: psk) else { throw TunnelError.invalid("PresharedKey") }
                peer.preSharedKey = key
            }
            for value in WgQuick.list(section["allowedips"]) {
                guard let range = IPAddressRange(from: value) else { throw TunnelError.invalid("AllowedIPs \(value)") }
                peer.allowedIPs.append(range)
            }
            if let endpointText = section["endpoint"]?.first {
                guard let endpoint = Endpoint(from: endpointText) else { throw TunnelError.invalid("Endpoint") }
                peer.endpoint = endpoint
            }
            if let keepAlive = section["persistentkeepalive"]?.first.flatMap({ UInt16($0) }) {
                peer.persistentKeepAlive = keepAlive
            }
            peers.append(peer)
        }
        guard !peers.isEmpty else { throw TunnelError.invalid("[Peer] manquant") }
        return TunnelConfiguration(name: name, interface: interface, peers: peers)
    }
}
