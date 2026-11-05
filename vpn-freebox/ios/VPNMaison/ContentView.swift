import NetworkExtension
import SwiftUI
import UniformTypeIdentifiers

struct ContentView: View {
    @EnvironmentObject private var tunnel: TunnelController
    @State private var showScanner = false
    @State private var showFilePicker = false
    @State private var showPaste = false
    @State private var showDeleteConfirm = false
    @State private var pasteText = ""
    @State private var importError: String?

    var body: some View {
        NavigationView {
            ScrollView {
                VStack(spacing: 20) {
                    if tunnel.hasConfig { connectedView } else { welcomeView }
                }
                .padding(20)
            }
            .navigationTitle("VPN Maison")
            .toolbar {
                if tunnel.hasConfig {
                    Menu {
                        Button("Remplacer (scanner un QR code)") { showScanner = true }
                        Button("Remplacer (fichier .conf)") { showFilePicker = true }
                        Button("Supprimer la configuration", role: .destructive) { showDeleteConfirm = true }
                    } label: { Image(systemName: "ellipsis.circle") }
                }
            }
        }
        .navigationViewStyle(.stack)
        .sheet(isPresented: $showScanner) {
            ZStack(alignment: .bottom) {
                QRScannerView { code in
                    showScanner = false
                    importConfig(code)
                }
                .ignoresSafeArea()
                Text("Visez le QR code WireGuard affiché dans Freebox OS")
                    .padding()
                    .background(.ultraThinMaterial, in: RoundedRectangle(cornerRadius: 12))
                    .padding(.bottom, 40)
            }
        }
        .sheet(isPresented: $showPaste) { pasteSheet }
        .fileImporter(isPresented: $showFilePicker, allowedContentTypes: [.item]) { result in
            switch result {
            case .success(let url): importFile(url)
            case .failure(let error): importError = error.localizedDescription
            }
        }
        .alert("Import impossible", isPresented: Binding(get: { importError != nil }, set: { if !$0 { importError = nil } })) {
            Button("Compris", role: .cancel) {}
        } message: { Text(importError ?? "") }
        .alert("Supprimer la configuration ?", isPresented: $showDeleteConfirm) {
            Button("Supprimer", role: .destructive) { Task { await tunnel.deleteConfig() } }
            Button("Annuler", role: .cancel) {}
        } message: { Text("Le VPN sera coupé et le profil retiré de Réglages > VPN.") }
    }

    // MARK: - Écran d'accueil

    private var welcomeView: some View {
        VStack(spacing: 16) {
            Image(systemName: "lock.shield.fill")
                .font(.system(size: 72))
                .foregroundColor(.blue)
                .padding(.top, 12)
            Text("Bienvenue !").font(.title.bold())
            Text("Importez la configuration WireGuard créée dans Freebox OS (tutoriel PDF, partie 2). C'est à faire une seule fois.")
                .multilineTextAlignment(.center)
            bigButton("Scanner le QR code", systemImage: "qrcode.viewfinder", filled: true) { showScanner = true }
            bigButton("Importer un fichier .conf", systemImage: "doc", filled: false) { showFilePicker = true }
            bigButton("Coller le texte", systemImage: "doc.on.clipboard", filled: false) { showPaste = true }
            Label("La configuration (et sa clé secrète) reste sur ce téléphone. Elle n'est envoyée à aucun serveur.",
                  systemImage: "lock.fill")
                .font(.footnote)
                .padding()
                .background(Color(.secondarySystemBackground), in: RoundedRectangle(cornerRadius: 12))
        }
    }

    // MARK: - Écran principal

    private var connectedView: some View {
        VStack(spacing: 18) {
            Button(action: toggle) {
                ZStack {
                    Circle().fill(statusColor).frame(width: 190, height: 190).shadow(radius: 8)
                    if isBusy {
                        ProgressView().progressViewStyle(.circular).tint(.white).scaleEffect(2)
                    } else {
                        VStack(spacing: 6) {
                            Image(systemName: "power").font(.system(size: 64, weight: .bold))
                            Text(tunnel.status == .connected ? "Déconnecter" : "Connecter").font(.headline)
                        }
                        .foregroundColor(.white)
                    }
                }
            }
            .disabled(isBusy)
            .accessibilityLabel("Connecter ou déconnecter")

            Text(statusText).font(.title2.bold())
                .foregroundColor(tunnel.status == .connected ? .green : .primary)

            if let message = tunnel.errorMessage {
                Label(message, systemImage: "exclamationmark.triangle.fill")
                    .padding()
                    .background(Color.orange.opacity(0.15), in: RoundedRectangle(cornerRadius: 12))
                    .onTapGesture { tunnel.errorMessage = nil }
            }

            VStack(alignment: .leading, spacing: 8) {
                Text("Ma connexion").font(.headline)
                infoRow("Adresse de la box", tunnel.summary?.endpointHost ?? "—")
                infoRow("Port", tunnel.summary?.endpointPort ?? "—")
                infoRow("Mon adresse dans le tunnel", tunnel.summary?.addresses.joined(separator: ", ") ?? "—")
                infoRow("Tout Internet passe par la box", tunnel.summary?.fullTunnel == true ? "Oui" : "Non")
                if let since = tunnel.connectedDate, tunnel.status == .connected {
                    TimelineView(.periodic(from: .now, by: 1)) { context in
                        infoRow("Connecté depuis", Self.duration(context.date.timeIntervalSince(since)))
                    }
                }
            }
            .padding()
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(Color(.secondarySystemBackground), in: RoundedRectangle(cornerRadius: 12))

            Text("Pour tester : coupez le Wi-Fi, restez en 4G/5G, connectez-vous puis ouvrez http://mafreebox.freebox.fr dans Safari.")
                .font(.footnote)
                .foregroundColor(.secondary)
        }
    }

    private var pasteSheet: some View {
        NavigationView {
            TextEditor(text: $pasteText)
                .font(.system(.footnote, design: .monospaced))
                .disableAutocorrection(true)
                .textInputAutocapitalization(.never)
                .padding()
                .navigationTitle("Coller la configuration")
                .navigationBarTitleDisplayMode(.inline)
                .toolbar {
                    ToolbarItem(placement: .cancellationAction) { Button("Annuler") { showPaste = false } }
                    ToolbarItem(placement: .confirmationAction) {
                        Button("Importer") {
                            showPaste = false
                            importConfig(pasteText)
                            pasteText = ""
                        }
                        .disabled(pasteText.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty)
                    }
                }
        }
    }

    // MARK: - Actions

    private func toggle() {
        tunnel.status == .connected || tunnel.status == .connecting ? tunnel.disconnect() : tunnel.connect()
    }

    private func importConfig(_ text: String) {
        Task {
            do { try await tunnel.importConfig(text) } catch { importError = error.localizedDescription }
        }
    }

    private func importFile(_ url: URL) {
        let scoped = url.startAccessingSecurityScopedResource()
        defer { if scoped { url.stopAccessingSecurityScopedResource() } }
        guard let data = try? Data(contentsOf: url), data.count < 64 * 1024,
              let text = String(data: data, encoding: .utf8) else {
            importError = "Impossible de lire ce fichier. Choisissez le fichier .conf téléchargé depuis Freebox OS."
            return
        }
        importConfig(text)
    }

    // MARK: - Aides d'affichage

    private var isBusy: Bool {
        tunnel.status == .connecting || tunnel.status == .disconnecting || tunnel.status == .reasserting
    }

    private var statusColor: Color {
        switch tunnel.status {
        case .connected: return .green
        case .connecting, .disconnecting, .reasserting: return .orange
        default: return .gray
        }
    }

    private var statusText: String {
        switch tunnel.status {
        case .connected: return "Connecté à ma Freebox"
        case .connecting, .reasserting: return "Connexion en cours…"
        case .disconnecting: return "Déconnexion…"
        default: return "Déconnecté"
        }
    }

    private func infoRow(_ label: String, _ value: String) -> some View {
        HStack {
            Text(label)
            Spacer()
            Text(value).bold().multilineTextAlignment(.trailing)
        }
        .font(.subheadline)
    }

    private func bigButton(_ title: String, systemImage: String, filled: Bool, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Label(title, systemImage: systemImage)
                .font(.headline)
                .frame(maxWidth: .infinity, minHeight: 56)
                .foregroundColor(filled ? .white : .blue)
                .background(filled ? Color.blue : Color.clear, in: RoundedRectangle(cornerRadius: 14))
                .overlay(RoundedRectangle(cornerRadius: 14).stroke(Color.blue, lineWidth: 2))
        }
    }

    private static func duration(_ seconds: TimeInterval) -> String {
        let s = max(0, Int(seconds))
        if s >= 3600 { return String(format: "%d h %02d min", s / 3600, (s % 3600) / 60) }
        if s >= 60 { return String(format: "%d min %02d s", s / 60, s % 60) }
        return "\(s) s"
    }
}
