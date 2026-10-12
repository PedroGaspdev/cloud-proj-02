Vagrant.configure("2") do |config|

    config.vm.box = "ubuntu/focal64" 
    config.vm.hostname = "runtime_conteiner"

    config.vm.network "forwarded_port", guest: 80, host: 8080

    config.vm.network "private_network", ip: "192.168.56.10"
    config.vm.provider "virtualbox" do |vb|
        vb.name = "cloud-projeto_2"
        vb.cpus = 1 
        vb.memory = 4096 
    end

    config.vm.provision "shell", path: "provision/bootstrap.sh"
end