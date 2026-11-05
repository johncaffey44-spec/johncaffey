import SwiftUI

@main
struct VPNMaisonApp: App {
    @StateObject private var tunnel = TunnelController()

    var body: some Scene {
        WindowGroup {
            ContentView()
                .environmentObject(tunnel)
        }
    }
}
